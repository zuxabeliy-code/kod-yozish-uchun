package main

// ESC/POS: kassadan kelgan chek hujjatini (qatorlar ro'yxati) printer baytlariga aylantiradi.

import (
	"bytes"
	"encoding/base64"
	"strings"
	"unicode/utf8"
)

// Line — chekdagi bitta element.
// Type: "text" (standart), "lr" (chap-o'ng ustun), "sep" (chiziq), "feed", "cut".
type Line struct {
	Type  string `json:"type,omitempty"`
	T     string `json:"t,omitempty"`     // matn
	L     string `json:"l,omitempty"`     // lr: chap
	R     string `json:"r,omitempty"`     // lr: o'ng
	Align string `json:"align,omitempty"` // l | c | r
	Bold  bool   `json:"b,omitempty"`
	Size  int    `json:"s,omitempty"` // 1 — oddiy, 2 — ikki barobar
	Ch    string `json:"ch,omitempty"`
	N     int    `json:"n,omitempty"`
	// Type == "img": 1-bitli rasm (logo). W — piksel (8 ga karrali), H — qatorlar, B64 — qatorma-qator baytlar (MSB birinchi, 1 = qora).
	W   int    `json:"w,omitempty"`
	H   int    `json:"h,omitempty"`
	B64 string `json:"b64,omitempty"`
	Ref string `json:"ref,omitempty"` // navbatda rasm nusxasi saqlangan kalit (B64 o'rniga)
}

type Doc struct {
	Lines []Line `json:"lines"`
	Beep  bool   `json:"beep,omitempty"`
	Font  string `json:"font,omitempty"` // "a" (oddiy) yoki "b" (kichik)
	LS    int    `json:"ls,omitempty"`   // qator oralig'i (nuqta), standart 30
	ML    int    `json:"ml,omitempty"`   // chap va o'ng chet (nuqta)
}

var (
	escInit      = []byte{0x1B, 0x40}
	escCodepage  = []byte{0x1B, 0x74, 17} // PC866 (kirill harflari uchun)
	escAlignL    = []byte{0x1B, 0x61, 0}
	escAlignC    = []byte{0x1B, 0x61, 1}
	escAlignR    = []byte{0x1B, 0x61, 2}
	escBoldOn    = []byte{0x1B, 0x45, 1}
	escBoldOff   = []byte{0x1B, 0x45, 0}
	gsSizeNormal = []byte{0x1D, 0x21, 0x00}
	gsSizeDouble = []byte{0x1D, 0x21, 0x11}
	gsCut        = []byte{0x1D, 0x56, 0x42, 0x00} // qisman kesish (oldin qog'oz suriladi)
	escBeep      = []byte{0x1B, 0x42, 0x02, 0x02} // Xprinter: 2 marta signal
)

// Qog'ozning nuqtalardagi eni: 80 mm — 576, 58 mm — 384 (203 dpi).
func paperDots(width int) int {
	if width == 58 {
		return 384
	}
	return 576
}

// Chop etiladigan qismdagi belgilar soni: A shrift 12 nuqta, B shrift 9 nuqta; chetlar (ml) ikki tomondan ayriladi.
// Chetsiz: 80 mm — 48 (A) / 64 (B), 58 mm — 32 (A) / 42 (B).
func charsPerLine(width int, font string, ml int) int {
	cw := 12
	if font == "b" {
		cw = 9
	}
	return maxInt(8, (paperDots(width)-2*ml)/cw)
}

// rasterBytes: logo rasmini to'liq kenglikdagi (chop etiladigan qism) bitmapga joylashtiradi va GS v 0 buyruqlariga aylantiradi.
// 58 mm da rasm 384/576 nisbatda proporsional kichraytiriladi.
func rasterBytes(ln Line, b64 string, paper, printable int, align string) []byte {
	data, err := base64.StdEncoding.DecodeString(b64)
	if err != nil || ln.W < 8 || ln.W > 1024 || ln.H < 1 || ln.H > 1024 || ln.W%8 != 0 || len(data) != ln.W/8*ln.H {
		return nil
	}
	srcRow := ln.W / 8
	get := func(x, y int) bool { return data[y*srcRow+x/8]&(0x80>>uint(x%8)) != 0 }
	w, h := ln.W, ln.H
	scale := 1.0
	if paper == 58 {
		scale = 384.0 / 576.0
	}
	if float64(w)*scale > float64(printable) {
		scale = float64(printable) / float64(w)
	}
	tw, th := int(float64(w)*scale), maxInt(1, int(float64(h)*scale))
	tw = maxInt(8, tw/8*8)
	full := (printable + 7) / 8 * 8
	off := 0
	switch align {
	case "c":
		off = (full - tw) / 2
	case "r":
		off = full - tw
	}
	off = off / 8 * 8
	rowBytes := full / 8
	bm := make([]byte, rowBytes*th)
	for y := 0; y < th; y++ {
		sy := minInt(h-1, int(float64(y)/scale))
		for x := 0; x < tw; x++ {
			sx := minInt(w-1, int(float64(x)/scale))
			if get(sx, sy) {
				px := off + x
				bm[y*rowBytes+px/8] |= 0x80 >> uint(px%8)
			}
		}
	}
	var out bytes.Buffer
	for y0 := 0; y0 < th; y0 += 128 {
		rows := minInt(128, th-y0)
		out.Write([]byte{0x1D, 0x76, 0x30, 0x00, byte(rowBytes & 0xFF), byte(rowBytes >> 8), byte(rows & 0xFF), byte(rows >> 8)})
		out.Write(bm[y0*rowBytes : (y0+rows)*rowBytes])
	}
	return out.Bytes()
}

func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}

// normalizeText: o'zbek lotin apostroflarini ASCII ga keltiradi, boshqarish belgilarini olib tashlaydi.
func normalizeText(s string) string {
	r := strings.NewReplacer("ʻ", "'", "ʼ", "'", "‘", "'", "’", "'", "`", "'", "“", "\"", "”", "\"", "«", "\"", "»", "\"",
		"—", "-", "–", "-", "№", "N", "…", "...", "\t", " ", "\r", "", " ", " ")
	s = r.Replace(s)
	var b strings.Builder
	for _, c := range s {
		if c < 0x20 && c != '\n' {
			continue
		}
		b.WriteRune(c)
	}
	return b.String()
}

// encode866: matnni PC866 kodlashiga o'tkazadi (lotin + kirill). Boshqa belgilar "?".
func encode866(s string) []byte {
	out := make([]byte, 0, len(s))
	for _, c := range s {
		switch {
		case c < 0x80:
			out = append(out, byte(c))
		case c >= 'А' && c <= 'п':
			out = append(out, byte(0x80+(c-'А')))
		case c >= 'р' && c <= 'я':
			out = append(out, byte(0xE0+(c-'р')))
		case c == 'Ё':
			out = append(out, 0xF0)
		case c == 'ё':
			out = append(out, 0xF1)
		case c == 'Ў':
			out = append(out, 0xF6)
		case c == 'ў':
			out = append(out, 0xF7)
		case c == '·':
			out = append(out, 0xFA)
		case c == '×':
			out = append(out, 'x')
		case c == '°':
			out = append(out, 0xF8)
		default:
			out = append(out, '?')
		}
	}
	return out
}

func runeLen(s string) int { return utf8.RuneCountInString(s) }

// wrap: matnni berilgan kenglikdagi qatorlarga bo'ladi (so'z bo'yicha).
func wrap(s string, width int) []string {
	if width < 4 {
		width = 4
	}
	var lines []string
	for _, para := range strings.Split(s, "\n") {
		// Boshidagi bo'shliqlar (chekdagi ichki qatorlar) saqlanadi
		indent := para[:len(para)-len(strings.TrimLeft(para, " "))]
		if len(indent) > width/2 {
			indent = ""
		}
		words := strings.Fields(para)
		cur := indent
		for _, w := range words {
			for runeLen(w) > width { // juda uzun so'z
				if cur != "" {
					lines = append(lines, cur)
					cur = ""
				}
				rs := []rune(w)
				lines = append(lines, string(rs[:width]))
				w = string(rs[width:])
			}
			if cur == "" || cur == indent {
				cur += w
			} else if runeLen(cur)+1+runeLen(w) <= width {
				cur += " " + w
			} else {
				lines = append(lines, cur)
				cur = w
			}
		}
		lines = append(lines, cur)
	}
	return lines
}

// leftRight: "Burger x2 ........ 60 000" ko'rinishidagi qatorlar.
func leftRight(l, r string, width int) []string {
	rw := runeLen(r)
	if rw >= width-2 {
		return append(wrap(l, width), strings.Repeat(" ", maxInt(0, width-rw))+r)
	}
	lw := width - rw - 1
	ls := wrap(l, lw)
	last := ls[len(ls)-1]
	ls[len(ls)-1] = last + strings.Repeat(" ", width-runeLen(last)-rw) + r
	return ls
}

// Render: hujjatni ESC/POS baytlariga aylantiradi.
func Render(d Doc, paperWidth int, cut bool) []byte {
	var b bytes.Buffer
	b.Write(escInit)
	b.Write(escCodepage)
	font := "a"
	if d.Font == "b" {
		font = "b"
		b.Write([]byte{0x1B, 0x4D, 1})
	}
	if d.LS >= 10 && d.LS <= 120 {
		b.Write([]byte{0x1B, 0x33, byte(d.LS)})
	}
	ml := d.ML
	if ml < 0 {
		ml = 0
	}
	if ml > 120 {
		ml = 120
	}
	printable := paperDots(paperWidth) - 2*ml
	if ml > 0 {
		b.Write([]byte{0x1D, 0x4C, byte(ml & 0xFF), byte(ml >> 8)})
		b.Write([]byte{0x1D, 0x57, byte(printable & 0xFF), byte(printable >> 8)})
	}
	base := charsPerLine(paperWidth, font, ml)
	for _, ln := range d.Lines {
		if ln.Type == "img" {
			b.Write(escAlignL)
			b.Write(rasterBytes(ln, ln.B64, paperWidth, printable, ln.Align))
			continue
		}
		size := ln.Size
		if size != 2 {
			size = 1
		}
		width := base / size
		switch ln.Align {
		case "c":
			b.Write(escAlignC)
		case "r":
			b.Write(escAlignR)
		default:
			b.Write(escAlignL)
		}
		if ln.Bold {
			b.Write(escBoldOn)
		}
		if size == 2 {
			b.Write(gsSizeDouble)
		}
		switch ln.Type {
		case "sep":
			ch := ln.Ch
			if ch == "" {
				ch = "-"
			}
			b.Write(encode866(strings.Repeat(string([]rune(ch)[0]), width)))
			b.WriteByte('\n')
		case "lr":
			for _, s := range leftRight(normalizeText(ln.L), normalizeText(ln.R), width) {
				b.Write(encode866(s))
				b.WriteByte('\n')
			}
		case "feed":
			n := ln.N
			if n < 1 || n > 10 {
				n = 1
			}
			b.Write(bytes.Repeat([]byte{'\n'}, n))
		case "cut":
			// alohida qayta ishlanadi (pastda)
		default:
			for _, s := range wrap(normalizeText(ln.T), width) {
				b.Write(encode866(s))
				b.WriteByte('\n')
			}
		}
		if size == 2 {
			b.Write(gsSizeNormal)
		}
		if ln.Bold {
			b.Write(escBoldOff)
		}
	}
	b.Write(escAlignL)
	if d.Beep {
		b.Write(escBeep)
	}
	b.Write([]byte("\n\n\n"))
	if cut {
		b.Write(gsCut)
	}
	return b.Bytes()
}

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}
