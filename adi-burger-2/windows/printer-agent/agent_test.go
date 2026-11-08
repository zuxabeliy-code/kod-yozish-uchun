package main

import (
	"bytes"
	"io"
	"net"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakePrinter: ESC/POS LAN printer taqlidi (9100 port o'rniga tasodifiy port).
type fakePrinter struct {
	ln       net.Listener
	mu       sync.Mutex
	received [][]byte
	paperEnd bool
}

func startFake(t *testing.T) *fakePrinter {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	f := &fakePrinter{ln: ln}
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go f.handle(c)
		}
	}()
	return f
}

func (f *fakePrinter) handle(c net.Conn) {
	defer c.Close()
	var all bytes.Buffer
	buf := make([]byte, 4096)
	for {
		_ = c.SetReadDeadline(time.Now().Add(2 * time.Second))
		n, err := c.Read(buf)
		if n > 0 {
			chunk := buf[:n]
			if bytes.Equal(chunk, []byte{0x10, 0x04, 0x04}) {
				f.mu.Lock()
				pe := f.paperEnd
				f.mu.Unlock()
				if pe {
					c.Write([]byte{0x72})
				} else {
					c.Write([]byte{0x12})
				}
				continue
			}
			all.Write(chunk)
		}
		if err != nil {
			break
		}
	}
	if all.Len() > 0 {
		f.mu.Lock()
		f.received = append(f.received, all.Bytes())
		f.mu.Unlock()
	}
}

func (f *fakePrinter) count() int { f.mu.Lock(); defer f.mu.Unlock(); return len(f.received) }

func (f *fakePrinter) port() int { return f.ln.Addr().(*net.TCPAddr).Port }

func setup(t *testing.T, port int) {
	dataDir = t.TempDir()
	if err := loadCfg("https://kassa.example.uz"); err != nil {
		t.Fatal(err)
	}
	c := getCfg()
	c.Printer = PrinterConfig{Mode: "lan", IP: "127.0.0.1", Port: port, Width: 80, Cut: true, StatusQuery: true}
	saveCfg(c)
	paperQuerySupported = -1
	queue = NewQueue(dataDir, getCfg)
}

func waitStatus(t *testing.T, id string, want ...string) *Job {
	deadline := time.Now().Add(8 * time.Second)
	for time.Now().Before(deadline) {
		j := queue.Get(id)
		for _, w := range want {
			if j != nil && j.Status == w {
				return j
			}
		}
		time.Sleep(50 * time.Millisecond)
	}
	j := queue.Get(id)
	t.Fatalf("kutilgan holat %v, bor: %+v", want, j)
	return nil
}

func doc() Doc {
	return Doc{Lines: []Line{{T: "ADI BURGER — OSHXONA", Align: "c", Size: 2, Bold: true}, {Type: "lr", L: "Big Cheese Burger", R: "x2"}, {Type: "sep"}}}
}

func TestPrintSuccessAndIdempotency(t *testing.T) {
	f := startFake(t)
	setup(t, f.port())
	go queue.Run()
	j, created := queue.Add(&Job{ID: "job-0000001", Kind: "kitchen", Doc: doc()})
	if !created {
		t.Fatal("yaratilmadi")
	}
	waitStatus(t, j.ID, StSent)
	// Xuddi shu ID qayta kelsa — ikkinchi marta chop etilmaydi
	_, created = queue.Add(&Job{ID: "job-0000001", Kind: "kitchen", Doc: doc()})
	if created {
		t.Fatal("takroriy chek yaratildi")
	}
	time.Sleep(1500 * time.Millisecond)
	if f.count() != 1 {
		t.Fatalf("printer %d marta chek oldi, 1 bo'lishi kerak", f.count())
	}
	got := f.received[0]
	if !bytes.Contains(got, []byte("ADI BURGER - OSHXONA")) || !bytes.HasSuffix(got, gsCut) {
		t.Fatalf("chek mazmuni noto'g'ri: %q", got)
	}
}

func TestPaperEndNothingSentThenRecover(t *testing.T) {
	f := startFake(t)
	f.paperEnd = true
	setup(t, f.port())
	autoRetryWindow = 1 * time.Second
	defer func() { autoRetryWindow = 2 * time.Minute }()
	go queue.Run()
	j, _ := queue.Add(&Job{ID: "job-paper-01", Kind: "customer", Doc: doc()})
	jj := waitStatus(t, j.ID, StError)
	if !strings.Contains(jj.Error, "qog'oz") {
		t.Fatalf("xato matni: %s", jj.Error)
	}
	if f.count() != 0 {
		t.Fatal("qog'oz yo'q bo'lsa ham chek yuborildi")
	}
	// Qog'oz qo'yildi → qayta yuborish
	f.mu.Lock()
	f.paperEnd = false
	f.mu.Unlock()
	if n := queue.RetryErrors(); n != 1 {
		t.Fatalf("retry: %d", n)
	}
	waitStatus(t, j.ID, StSent)
	if f.count() != 1 {
		t.Fatalf("chek soni %d", f.count())
	}
}

func TestPrinterOffline(t *testing.T) {
	f := startFake(t)
	port := f.port()
	f.ln.Close() // printer o'chiq
	setup(t, port)
	autoRetryWindow = 500 * time.Millisecond
	defer func() { autoRetryWindow = 2 * time.Minute }()
	go queue.Run()
	j, _ := queue.Add(&Job{ID: "job-offline-1", Kind: "kitchen", Doc: doc()})
	jj := waitStatus(t, j.ID, StError)
	if !strings.Contains(jj.Error, "rad etdi") && !strings.Contains(jj.Error, "javob bermayapti") {
		t.Fatalf("xato matni: %s", jj.Error)
	}
	st := doCheck()
	if st.OK {
		t.Fatal("o'chiq printer OK ko'rsatildi")
	}
}

func TestOrderPreservedAndReprint(t *testing.T) {
	f := startFake(t)
	setup(t, f.port())
	go queue.Run()
	ids := []string{"job-order-001", "job-order-002", "job-order-003"}
	for i, id := range ids {
		d := Doc{Lines: []Line{{T: "CHEK " + string(rune('A'+i))}}}
		queue.Add(&Job{ID: id, Kind: "kitchen", Doc: d})
	}
	for _, id := range ids {
		waitStatus(t, id, StSent)
	}
	for i := range ids {
		if !bytes.Contains(f.received[i], []byte("CHEK "+string(rune('A'+i)))) {
			t.Fatalf("tartib buzildi: %d", i)
		}
	}
	n, err := queue.Reprint("job-order-002", "job-order-002-r")
	if err != nil {
		t.Fatal(err)
	}
	waitStatus(t, n.ID, StSent)
	if queue.Get("job-order-002").Status != StReprinted {
		t.Fatal("eski chek 'qayta chiqarildi' bo'lmadi")
	}
}

func TestCrashDuringSendBecomesUnknown(t *testing.T) {
	dataDir = t.TempDir()
	os.WriteFile(dataDir+"/jobs.json", []byte(`[{"id":"job-crash-01","kind":"kitchen","doc":{"lines":[{"t":"x"}]},"copies":1,"status":"sending","created_at":"2026-01-01T10:00:00Z","updated_at":"2026-01-01T10:00:00Z"}]`), 0o600)
	q := NewQueue(dataDir, getCfg)
	if q.Get("job-crash-01").Status != StUnknown {
		t.Fatal("yuborish paytida o'chgan chek 'noma'lum' bo'lishi kerak")
	}
}

func TestRenderWidths(t *testing.T) {
	out := Render(Doc{Lines: []Line{{Type: "lr", L: "Big Cheese Lavash", R: "39 000"}}}, 58, false)
	for _, ln := range strings.Split(string(out), "\n") {
		clean := strings.Map(func(r rune) rune {
			if r < 0x20 || r == 0x1b || r == 0x1d {
				return -1
			}
			return r
		}, ln)
		if strings.Contains(clean, "39 000") && len(clean) > 32+6 {
			t.Fatalf("58mm qator juda uzun: %q", clean)
		}
	}
	if !bytes.Contains(encode866("Привет"), []byte{0x8F}) {
		t.Fatal("kirill kodlash")
	}
	_ = io.Discard
}
