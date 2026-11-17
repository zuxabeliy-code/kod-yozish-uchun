package com.jarvis.ai.root;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeUnit;

/**
 * ROOT moduli — 1-bosqichda FAQAT holatni tekshiradi.
 *
 * Xavfsizlik qoidalari (keyingi bosqichlar uchun ham):
 *  - ROOT faqat foydalanuvchi tugmani bosganda va Magisk ruxsat berganda ishlatiladi.
 *  - Ixtiyoriy shell buyruq bajarilmaydi: faqat oq ro'yxatdagi (whitelist) aniq buyruqlar.
 *  - Gemini/AI javobidan kelgan matn hech qachon root buyrug'i sifatida bajarilmaydi.
 * Batafsil reja: jarvis/docs/ROOT_MODUL.md
 */
public final class RootManager {

    public enum Status { GRANTED, DENIED, NOT_ROOTED, TIMEOUT }

    private RootManager() {
    }

    /** BLOKLOVCHI (Magisk oynasi chiqishi mumkin) — fon oqimida chaqiring. */
    public static Status check() {
        Process p = null;
        try {
            p = new ProcessBuilder("su", "-c", "id").redirectErrorStream(true).start();
            if (!p.waitFor(15, TimeUnit.SECONDS)) {
                return Status.TIMEOUT;
            }
            String out;
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream(), StandardCharsets.UTF_8))) {
                out = r.readLine();
            }
            return out != null && out.contains("uid=0") ? Status.GRANTED : Status.DENIED;
        } catch (IOException e) {
            return Status.NOT_ROOTED; // "su" topilmadi
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            return Status.TIMEOUT;
        } finally {
            if (p != null) p.destroy();
        }
    }

    public static String describe(Status s) {
        switch (s) {
            case GRANTED: return "ROOT: ruxsat berildi ✔ (Magisk)";
            case DENIED: return "ROOT: Magisk ruxsat bermadi";
            case TIMEOUT: return "ROOT: javob kelmadi (Magisk oynasini tasdiqlang)";
            default: return "ROOT: topilmadi (su yo'q)";
        }
    }
}
