package main

// ADI BURGER Printer xizmati.
// Faqat 127.0.0.1 da tinglaydi. Brauzerdagi kassa (ruxsat etilgan sayt) cheklarni shu yerga yuboradi,
// xizmat ularni ESC/POS buyruqlari bilan to'g'ridan-to'g'ri printerga (LAN 9100 yoki Windows spooler) yuboradi.
// Chrome Print oynasi ishlatilmaydi.

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

const Version = "2.0.0"

type PrinterConfig struct {
	Mode        string `json:"mode"` // lan | usb
	IP          string `json:"ip"`
	Port        int    `json:"port"`
	PrinterName string `json:"printer_name"`
	Model       string `json:"model"`
	Width       int    `json:"width"` // 58 | 80
	Cut         bool   `json:"cut"`
	StatusQuery bool   `json:"status_query"`
}

type Config struct {
	Listen         string        `json:"listen"`
	Token          string        `json:"token"`
	AllowedOrigins []string      `json:"allowed_origins"`
	Printer        PrinterConfig `json:"printer"`
}

var (
	dataDir string
	cfgMu   sync.RWMutex
	cfg     Config
	queue   *Queue
	logger  *log.Logger
)

func logf(f string, a ...any) {
	if logger != nil {
		logger.Printf(f, a...)
	}
}

func getCfg() Config {
	cfgMu.RLock()
	defer cfgMu.RUnlock()
	c := cfg
	c.AllowedOrigins = append([]string(nil), cfg.AllowedOrigins...)
	return c
}

func saveCfg(c Config) error {
	cfgMu.Lock()
	cfg = c
	cfgMu.Unlock()
	b, _ := json.MarshalIndent(c, "", "  ")
	tmp := filepath.Join(dataDir, "config.json.tmp")
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, filepath.Join(dataDir, "config.json"))
}

func newID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	h := hex.EncodeToString(b)
	return h[0:8] + "-" + h[8:12] + "-" + h[12:16] + "-" + h[16:20] + "-" + h[20:]
}

func normOrigin(o string) string {
	u, err := url.Parse(strings.TrimSpace(o))
	if err != nil || u.Scheme == "" || u.Host == "" {
		return ""
	}
	return strings.ToLower(u.Scheme + "://" + u.Host)
}

func loadCfg(extraOrigin string) error {
	c := Config{Listen: "127.0.0.1:9123", Printer: PrinterConfig{Mode: "lan", Port: 9100, Width: 80, Cut: true, StatusQuery: true}}
	if b, err := os.ReadFile(filepath.Join(dataDir, "config.json")); err == nil {
		if err := json.Unmarshal(b, &c); err != nil {
			return fmt.Errorf("config.json buzilgan: %w", err)
		}
	}
	if c.Token == "" {
		c.Token = strings.ReplaceAll(newID()+newID(), "-", "")
	}
	if o := normOrigin(extraOrigin); o != "" {
		found := false
		for _, x := range c.AllowedOrigins {
			if x == o {
				found = true
			}
		}
		if !found {
			c.AllowedOrigins = append(c.AllowedOrigins, o)
		}
	}
	// Xavfsizlik: faqat localhost
	host, _, err := net.SplitHostPort(c.Listen)
	if err != nil || (host != "127.0.0.1" && host != "localhost") {
		c.Listen = "127.0.0.1:9123"
	}
	return saveCfg(c)
}

// ---------- HTTP ----------

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func originAllowed(o string) bool {
	o = normOrigin(o)
	if o == "" {
		return false
	}
	for _, x := range getCfg().AllowedOrigins {
		if x == o {
			return true
		}
	}
	return false
}

// guard: Origin tekshiruvi (begona saytlar printerga buyruq yubora olmaydi), CORS, token.
func guard(needToken bool, h http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		origin := r.Header.Get("Origin")
		// Host sarlavhasi faqat localhost bo'lishi kerak (DNS rebinding hujumidan himoya)
		host := r.Host
		if h, _, err := net.SplitHostPort(r.Host); err == nil {
			host = h
		}
		if host != "127.0.0.1" && host != "localhost" {
			writeJSON(w, http.StatusForbidden, map[string]any{"ok": false, "error": "host ruxsat etilmagan"})
			return
		}
		if origin != "" {
			if !originAllowed(origin) {
				logf("rad etildi: origin %s %s", origin, r.URL.Path)
				writeJSON(w, http.StatusForbidden, map[string]any{"ok": false, "error": "Bu sayt printerga ulanishga ruxsatga ega emas: " + origin})
				return
			}
			w.Header().Set("Access-Control-Allow-Origin", origin)
			w.Header().Set("Vary", "Origin")
			w.Header().Set("Access-Control-Allow-Headers", "Content-Type, X-Agent-Token")
			w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
			w.Header().Set("Access-Control-Allow-Private-Network", "true")
			w.Header().Set("Access-Control-Max-Age", "600")
		} else if needToken && r.Header.Get("X-Agent-Token") == "" {
			// Brauzerdan tashqari so'rov — faqat token bilan
			writeJSON(w, http.StatusForbidden, map[string]any{"ok": false, "error": "origin yoki token kerak"})
			return
		}
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		if needToken {
			t := r.Header.Get("X-Agent-Token")
			if t == "" || t != getCfg().Token {
				writeJSON(w, http.StatusUnauthorized, map[string]any{"ok": false, "error": "token noto'g'ri", "code": "TOKEN"})
				return
			}
		}
		r.Body = http.MaxBytesReader(w, r.Body, 1<<20)
		h(w, r)
	}
}

func readJSON(r *http.Request, v any) error {
	b, err := io.ReadAll(r.Body)
	if err != nil {
		return err
	}
	return json.Unmarshal(b, v)
}

var lastCheck atomic.Value // PrinterState

func doCheck() PrinterState {
	s := printerCheck(getCfg().Printer)
	lastCheck.Store(s)
	queue.SetState(s)
	return s
}

func statusPayload() map[string]any {
	c := getCfg()
	st := queue.State()
	if st.CheckedAt == "" {
		st = doCheck()
	}
	return map[string]any{
		"ok": true, "app": "adi-printer", "version": Version,
		"printer_config": c.Printer, "printer": st, "queue": queue.Counts(),
	}
}

var ipRe = regexp.MustCompile(`^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$`)

func validIP(s string) bool {
	m := ipRe.FindStringSubmatch(s)
	if m == nil {
		return false
	}
	for _, p := range m[1:] {
		var n int
		fmt.Sscan(p, &n)
		if n > 255 {
			return false
		}
	}
	return true
}

func routes() *http.ServeMux {
	mux := http.NewServeMux()

	mux.HandleFunc("/ping", guard(false, func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 200, map[string]any{"ok": true, "app": "adi-printer", "version": Version})
	}))

	// Ulash: ruxsat etilgan sayt (kassa) tokenni oladi. Begona sayt Origin tekshiruvidan o'tolmaydi.
	mux.HandleFunc("/pair", guard(false, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost || r.Header.Get("Origin") == "" {
			writeJSON(w, 400, map[string]any{"ok": false, "error": "faqat kassa sahifasidan"})
			return
		}
		writeJSON(w, 200, map[string]any{"ok": true, "token": getCfg().Token, "version": Version})
	}))

	mux.HandleFunc("/status", guard(true, func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 200, statusPayload())
	}))

	mux.HandleFunc("/check", guard(true, func(w http.ResponseWriter, r *http.Request) {
		s := doCheck()
		writeJSON(w, 200, map[string]any{"ok": true, "printer": s, "queue": queue.Counts()})
	}))

	mux.HandleFunc("/config", guard(true, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeJSON(w, 200, map[string]any{"ok": true, "printer_config": getCfg().Printer})
			return
		}
		var p PrinterConfig
		if err := readJSON(r, &p); err != nil {
			writeJSON(w, 400, map[string]any{"ok": false, "error": "noto'g'ri ma'lumot"})
			return
		}
		p.IP = strings.TrimSpace(p.IP)
		p.PrinterName = strings.TrimSpace(p.PrinterName)
		if p.Mode != "usb" {
			p.Mode = "lan"
			if !validIP(p.IP) {
				writeJSON(w, 400, map[string]any{"ok": false, "error": "IP manzil noto'g'ri. Masalan: 192.168.1.87"})
				return
			}
			if p.Port < 1 || p.Port > 65535 {
				writeJSON(w, 400, map[string]any{"ok": false, "error": "Port 1–65535 oralig'ida bo'lsin (odatda 9100)"})
				return
			}
		} else if p.PrinterName == "" {
			writeJSON(w, 400, map[string]any{"ok": false, "error": "Windows printerini tanlang"})
			return
		}
		if p.Width != 58 {
			p.Width = 80
		}
		if len(p.Model) > 60 {
			p.Model = p.Model[:60]
		}
		c := getCfg()
		c.Printer = p
		if err := saveCfg(c); err != nil {
			writeJSON(w, 500, map[string]any{"ok": false, "error": "sozlamani saqlab bo'lmadi: " + err.Error()})
			return
		}
		atomic.StoreInt32(&paperQuerySupported, -1)
		logf("printer sozlamasi saqlandi: %s %s", p.Mode, target(p))
		s := doCheck()
		queue.kick()
		writeJSON(w, 200, map[string]any{"ok": true, "printer_config": p, "printer": s})
	}))

	mux.HandleFunc("/print", guard(true, func(w http.ResponseWriter, r *http.Request) {
		var in struct {
			ID       string `json:"id"`
			Kind     string `json:"kind"`
			OrderRef string `json:"order_ref"`
			Title    string `json:"title"`
			Doc      Doc    `json:"doc"`
			Copies   int    `json:"copies"`
		}
		if err := readJSON(r, &in); err != nil || len(in.ID) < 8 || len(in.ID) > 64 || len(in.Doc.Lines) == 0 || len(in.Doc.Lines) > 400 {
			writeJSON(w, 400, map[string]any{"ok": false, "error": "chek ma'lumoti noto'g'ri"})
			return
		}
		switch in.Kind {
		case "kitchen", "customer", "cancel", "zreport", "test", "report":
		default:
			writeJSON(w, 400, map[string]any{"ok": false, "error": "chek turi noto'g'ri"})
			return
		}
		j, created := queue.Add(&Job{ID: in.ID, Kind: in.Kind, OrderRef: in.OrderRef, Title: in.Title, Doc: in.Doc, Copies: in.Copies})
		writeJSON(w, 200, map[string]any{"ok": true, "job": j, "created": created})
	}))

	mux.HandleFunc("/job", guard(true, func(w http.ResponseWriter, r *http.Request) {
		j := queue.Get(r.URL.Query().Get("id"))
		if j == nil {
			writeJSON(w, 404, map[string]any{"ok": false, "error": "chek topilmadi"})
			return
		}
		j.Doc = Doc{}
		writeJSON(w, 200, map[string]any{"ok": true, "job": j})
	}))

	mux.HandleFunc("/jobs", guard(true, func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 200, map[string]any{"ok": true, "jobs": queue.List(100), "queue": queue.Counts()})
	}))

	mux.HandleFunc("/jobs/retry", guard(true, func(w http.ResponseWriter, r *http.Request) {
		var in struct {
			ID string `json:"id"`
		}
		_ = readJSON(r, &in)
		if err := queue.Retry(in.ID); err != nil {
			writeJSON(w, 400, map[string]any{"ok": false, "error": err.Error()})
			return
		}
		writeJSON(w, 200, map[string]any{"ok": true})
	}))

	mux.HandleFunc("/jobs/retry-errors", guard(true, func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 200, map[string]any{"ok": true, "count": queue.RetryErrors()})
	}))

	mux.HandleFunc("/jobs/reprint", guard(true, func(w http.ResponseWriter, r *http.Request) {
		var in struct {
			ID    string `json:"id"`
			NewID string `json:"new_id"`
		}
		_ = readJSON(r, &in)
		if len(in.NewID) < 8 {
			in.NewID = newID()
		}
		j, err := queue.Reprint(in.ID, in.NewID)
		if err != nil {
			writeJSON(w, 400, map[string]any{"ok": false, "error": err.Error()})
			return
		}
		writeJSON(w, 200, map[string]any{"ok": true, "job": j})
	}))

	mux.HandleFunc("/test", guard(true, func(w http.ResponseWriter, r *http.Request) {
		c := getCfg().Printer
		now := time.Now().Format("02.01.2006 15:04:05")
		doc := Doc{Beep: true, Lines: []Line{
			{T: "ADI BURGER", Align: "c", Bold: true, Size: 2},
			{T: "TEST CHEK", Align: "c", Bold: true},
			{Type: "sep"},
			{Type: "lr", L: "Ulanish", R: strings.ToUpper(c.Mode)},
			{Type: "lr", L: "Printer", R: target(c)},
			{Type: "lr", L: "Model", R: map[bool]string{true: c.Model, false: "-"}[c.Model != ""]},
			{Type: "lr", L: "Chek eni", R: fmt.Sprintf("%d mm", c.Width)},
			{Type: "lr", L: "Xizmat versiyasi", R: Version},
			{Type: "lr", L: "Vaqt", R: now},
			{Type: "sep"},
			{T: "O'zbekcha: o'g'il, g'isht, sho'rva", Align: "l"},
			{T: "1234567890 ABCDEFGHIJKLMNOPQRSTUVWXYZ", Align: "l"},
			{Type: "sep", Ch: "="},
			{T: "Printer ishlayapti!", Align: "c", Bold: true},
		}}
		j, _ := queue.Add(&Job{ID: newID(), Kind: "test", Title: "Test chek", Doc: doc})
		writeJSON(w, 200, map[string]any{"ok": true, "job": j})
	}))

	mux.HandleFunc("/printers", guard(true, func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 200, map[string]any{"ok": true, "printers": listPrinters()})
	}))

	return mux
}

func main() {
	exe, _ := os.Executable()
	flag.StringVar(&dataDir, "data", filepath.Dir(exe), "sozlama va navbat papkasi")
	origin := flag.String("origin", "", "kassa sayti manzili (ruxsat beriladi), masalan https://adiburger.uz")
	setupOnly := flag.Bool("setup", false, "faqat sozlamani yozib chiqish")
	showVer := flag.Bool("version", false, "versiya")
	flag.Parse()
	if *showVer {
		fmt.Println(Version)
		return
	}
	_ = os.MkdirAll(dataDir, 0o755)
	lf, err := os.OpenFile(filepath.Join(dataDir, "agent.log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err == nil {
		if st, e := lf.Stat(); e == nil && st.Size() > 2<<20 {
			lf.Close()
			_ = os.Rename(filepath.Join(dataDir, "agent.log"), filepath.Join(dataDir, "agent.old.log"))
			lf, _ = os.OpenFile(filepath.Join(dataDir, "agent.log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
		}
		logger = log.New(lf, "", log.LstdFlags)
	} else {
		logger = log.New(os.Stderr, "", log.LstdFlags)
	}
	if err := loadCfg(*origin); err != nil {
		logf("XATO: %v", err)
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	if *setupOnly {
		fmt.Println("OK:", filepath.Join(dataDir, "config.json"))
		return
	}
	queue = NewQueue(dataDir, getCfg)
	ln, err := net.Listen("tcp", getCfg().Listen)
	if err != nil {
		// Odatda: xizmat allaqachon ishlab turibdi
		logf("port band (%s): %v — xizmat allaqachon ishlayotgan bo'lishi mumkin", getCfg().Listen, err)
		os.Exit(0)
	}
	logf("ADI BURGER printer xizmati %s ishga tushdi: %s, ruxsat: %v", Version, getCfg().Listen, getCfg().AllowedOrigins)
	go queue.Run()
	go func() {
		// Printer holati har 30 soniyada tekshiriladi (chop etmasdan)
		for {
			if queue.Counts()[StSending] == 0 {
				doCheck()
			}
			time.Sleep(30 * time.Second)
		}
	}()
	srv := &http.Server{Handler: routes(), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 15 * time.Second}
	if err := srv.Serve(ln); err != nil {
		logf("server to'xtadi: %v", err)
	}
}
