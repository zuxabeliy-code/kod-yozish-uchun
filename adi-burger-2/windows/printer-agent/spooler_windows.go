//go:build windows

package main

// USB (yoki Windowsga o'rnatilgan har qanday) printer: Windows spooler orqali RAW ESC/POS yuborish.

import (
	"fmt"
	"syscall"
	"time"
	"unsafe"
)

var (
	winspool          = syscall.NewLazyDLL("winspool.drv")
	pOpenPrinter      = winspool.NewProc("OpenPrinterW")
	pClosePrinter     = winspool.NewProc("ClosePrinter")
	pStartDocPrinter  = winspool.NewProc("StartDocPrinterW")
	pEndDocPrinter    = winspool.NewProc("EndDocPrinter")
	pStartPagePrinter = winspool.NewProc("StartPagePrinter")
	pEndPagePrinter   = winspool.NewProc("EndPagePrinter")
	pWritePrinter     = winspool.NewProc("WritePrinter")
	pEnumPrinters     = winspool.NewProc("EnumPrintersW")
)

type docInfo1 struct {
	DocName    *uint16
	OutputFile *uint16
	Datatype   *uint16
}

type printerInfo4 struct {
	PrinterName *uint16
	ServerName  *uint16
	Attributes  uint32
}

func openPrinter(name string) (syscall.Handle, error) {
	var h syscall.Handle
	n, _ := syscall.UTF16PtrFromString(name)
	r, _, err := pOpenPrinter.Call(uintptr(unsafe.Pointer(n)), uintptr(unsafe.Pointer(&h)), 0)
	if r == 0 {
		return 0, fmt.Errorf("Windows printer topilmadi: \"%s\" (%v)", name, err)
	}
	return h, nil
}

func spoolerCheck(c PrinterConfig) PrinterState {
	st := PrinterState{Configured: configured(c), Target: target(c), CheckedAt: time.Now().Format(time.RFC3339), Paper: "unknown"}
	if !st.Configured {
		st.Error = "Windows printer tanlanmagan."
		return st
	}
	h, err := openPrinter(c.PrinterName)
	if err != nil {
		st.Error = err.Error() + ". Printer nomini ro'yxatdan qayta tanlang."
		return st
	}
	pClosePrinter.Call(uintptr(h))
	st.OK = true
	return st
}

func spoolerSend(c PrinterConfig, data []byte) error {
	h, err := openPrinter(c.PrinterName)
	if err != nil {
		return &SendError{Msg: err.Error()}
	}
	defer pClosePrinter.Call(uintptr(h))
	name, _ := syscall.UTF16PtrFromString("ADI BURGER chek")
	raw, _ := syscall.UTF16PtrFromString("RAW")
	di := docInfo1{DocName: name, Datatype: raw}
	if r, _, e := pStartDocPrinter.Call(uintptr(h), 1, uintptr(unsafe.Pointer(&di))); r == 0 {
		return &SendError{Msg: fmt.Sprintf("Windows chop etishni boshlamadi: %v", e)}
	}
	defer pEndDocPrinter.Call(uintptr(h))
	pStartPagePrinter.Call(uintptr(h))
	var written uint32
	r, _, e := pWritePrinter.Call(uintptr(h), uintptr(unsafe.Pointer(&data[0])), uintptr(len(data)), uintptr(unsafe.Pointer(&written)))
	pEndPagePrinter.Call(uintptr(h))
	if r == 0 || int(written) != len(data) {
		return &SendError{Msg: fmt.Sprintf("Windows printerga yozib bo'lmadi: %v", e), Written: written > 0}
	}
	return nil
}

func listPrinters() []string {
	const flags = 2 | 4 // PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS
	var needed, returned uint32
	pEnumPrinters.Call(flags, 0, 4, 0, 0, uintptr(unsafe.Pointer(&needed)), uintptr(unsafe.Pointer(&returned)))
	if needed == 0 {
		return []string{}
	}
	buf := make([]byte, needed)
	r, _, _ := pEnumPrinters.Call(flags, 0, 4, uintptr(unsafe.Pointer(&buf[0])), uintptr(needed), uintptr(unsafe.Pointer(&needed)), uintptr(unsafe.Pointer(&returned)))
	if r == 0 {
		return []string{}
	}
	out := make([]string, 0, returned)
	size := unsafe.Sizeof(printerInfo4{})
	for i := uint32(0); i < returned; i++ {
		pi := (*printerInfo4)(unsafe.Pointer(&buf[uintptr(i)*size]))
		out = append(out, utf16PtrToString(pi.PrinterName))
	}
	return out
}

func utf16PtrToString(p *uint16) string {
	if p == nil {
		return ""
	}
	var s []uint16
	for ptr := unsafe.Pointer(p); ; ptr = unsafe.Pointer(uintptr(ptr) + 2) {
		c := *(*uint16)(ptr)
		if c == 0 {
			break
		}
		s = append(s, c)
	}
	return syscall.UTF16ToString(s)
}
