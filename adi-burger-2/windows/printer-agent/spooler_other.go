//go:build !windows

package main

// Windows bo'lmagan tizimlar (sinov uchun): spooler yo'q.

import "time"

func spoolerCheck(c PrinterConfig) PrinterState {
	return PrinterState{Configured: configured(c), Target: target(c), CheckedAt: time.Now().Format(time.RFC3339), Error: "USB (Windows spooler) rejimi faqat Windowsda ishlaydi."}
}

func spoolerSend(c PrinterConfig, data []byte) error {
	return &SendError{Msg: "USB (Windows spooler) rejimi faqat Windowsda ishlaydi."}
}

func listPrinters() []string { return []string{} }
