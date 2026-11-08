package main

import (
	"errors"
	"fmt"
	"net"
	"strings"
	"sync/atomic"
	"time"
)

// SendError: printerga yuborishdagi xato. Written — printerga biror bayt ketganmi
// (ketgan bo'lsa natija noma'lum hisoblanadi va avtomatik qayta yuborilmaydi).
type SendError struct {
	Msg     string
	Written bool
}

func (e *SendError) Error() string { return e.Msg }

// PrinterState — oxirgi tekshiruv natijasi.
type PrinterState struct {
	Configured bool   `json:"configured"`
	OK         bool   `json:"ok"`
	Error      string `json:"error,omitempty"`
	Target     string `json:"target,omitempty"`
	CheckedAt  string `json:"checked_at,omitempty"`
	Paper      string `json:"paper,omitempty"` // ok | near_end | end | unknown
	LatencyMs  int64  `json:"latency_ms,omitempty"`
}

func target(c PrinterConfig) string {
	if c.Mode == "usb" {
		return "Windows printer: " + c.PrinterName
	}
	return net.JoinHostPort(c.IP, fmt.Sprint(c.Port))
}

func configured(c PrinterConfig) bool {
	if c.Mode == "usb" {
		return c.PrinterName != ""
	}
	return c.IP != "" && c.Port > 0
}

// friendlyNetErr: tarmoq xatosini kassir tushunadigan tilga o'giradi.
func friendlyNetErr(err error, c PrinterConfig) string {
	s := err.Error()
	var ne net.Error
	switch {
	case errors.As(err, &ne) && ne.Timeout():
		return fmt.Sprintf("Printer javob bermayapti (%s). Printer yoqilganini, LAN kabeli ulanganini va IP manzil to'g'riligini tekshiring.", target(c))
	case strings.Contains(s, "refused"):
		return fmt.Sprintf("Printer ulanishni rad etdi (%s). Port noto'g'ri bo'lishi mumkin (odatda 9100).", target(c))
	case strings.Contains(s, "unreachable") || strings.Contains(s, "no route"):
		return fmt.Sprintf("Printer tarmog'iga yo'l yo'q (%s). Kompyuter va printer bitta tarmoqdami? IP manzilni tekshiring.", target(c))
	case strings.Contains(s, "reset") || strings.Contains(s, "broken pipe"):
		return "Printer ulanishni uzib qo'ydi. Printerni o'chirib-yoqing."
	}
	return "Printer bilan aloqa xatosi: " + s
}

// paperQuerySupported: printer DLE EOT ga javob beradimi (-1 noma'lum, 0 yo'q, 1 ha).
// Javob bermaydigan modelda har chekda kutib o'tirmaslik uchun eslab qolinadi.
var paperQuerySupported = int32(-1)

// queryStatus: DLE EOT 4 (qog'oz sensori). Javob bo'lmasa "unknown" (ba'zi modellar javob bermaydi).
func queryPaper(conn net.Conn) string {
	_ = conn.SetDeadline(time.Now().Add(500 * time.Millisecond))
	defer conn.SetDeadline(time.Time{})
	if _, err := conn.Write([]byte{0x10, 0x04, 0x04}); err != nil {
		return "unknown"
	}
	buf := make([]byte, 1)
	n, err := conn.Read(buf)
	if err != nil || n != 1 || buf[0]&0x93 != 0x12 { // ESC/POS: 0x12 belgisi bo'lishi shart
		atomic.StoreInt32(&paperQuerySupported, 0)
		return "unknown"
	}
	atomic.StoreInt32(&paperQuerySupported, 1)
	switch {
	case buf[0]&0x60 == 0x60:
		return "end"
	case buf[0]&0x0C == 0x0C:
		return "near_end"
	}
	return "ok"
}

// lanCheck: printerga ulanib ko'radi va (imkon bo'lsa) qog'ozni tekshiradi. Hech narsa chop etmaydi.
func lanCheck(c PrinterConfig) PrinterState {
	st := PrinterState{Configured: configured(c), Target: target(c), CheckedAt: time.Now().Format(time.RFC3339)}
	if !st.Configured {
		st.Error = "Printer sozlanmagan: IP manzil va portni kiriting."
		return st
	}
	t := time.Now()
	conn, err := net.DialTimeout("tcp", target(c), 3*time.Second)
	if err != nil {
		st.Error = friendlyNetErr(err, c)
		return st
	}
	defer conn.Close()
	st.LatencyMs = time.Since(t).Milliseconds()
	st.Paper = "unknown"
	if c.StatusQuery {
		st.Paper = queryPaper(conn)
	}
	if st.Paper == "end" {
		st.Error = "Printerda qog'oz tugagan. Yangi rulon qo'ying."
		return st
	}
	st.OK = true
	return st
}

// lanSend: chekni printerga yuboradi. Qog'oz tugagan bo'lsa hech narsa yubormaydi.
func lanSend(c PrinterConfig, data []byte) error {
	conn, err := net.DialTimeout("tcp", target(c), 3*time.Second)
	if err != nil {
		return &SendError{Msg: friendlyNetErr(err, c)}
	}
	defer conn.Close()
	if c.StatusQuery && atomic.LoadInt32(&paperQuerySupported) != 0 && queryPaper(conn) == "end" {
		return &SendError{Msg: "Printerda qog'oz tugagan. Yangi rulon qo'ying."}
	}
	_ = conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
	n, err := conn.Write(data)
	if err != nil || n != len(data) {
		if err == nil {
			err = errors.New("to'liq yuborilmadi")
		}
		return &SendError{Msg: friendlyNetErr(err, c), Written: n > 0}
	}
	// Ulanishni darhol yopmasdan printer ma'lumotni qabul qilishiga qisqa vaqt beramiz
	if tc, ok := conn.(*net.TCPConn); ok {
		_ = tc.CloseWrite()
		_ = conn.SetReadDeadline(time.Now().Add(300 * time.Millisecond))
		buf := make([]byte, 64)
		_, _ = conn.Read(buf)
	}
	return nil
}

func printerCheck(c PrinterConfig) PrinterState {
	if c.Mode == "usb" {
		return spoolerCheck(c)
	}
	return lanCheck(c)
}

func printerSend(c PrinterConfig, data []byte) error {
	if !configured(c) {
		return &SendError{Msg: "Printer sozlanmagan. Kassada ⚙ → Printer bo'limida IP manzil va portni kiriting."}
	}
	if c.Mode == "usb" {
		return spoolerSend(c, data)
	}
	return lanSend(c, data)
}
