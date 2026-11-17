package com.jarvis.ai.commands;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNull;

import com.jarvis.ai.commands.CommandRouter.Command;
import com.jarvis.ai.commands.CommandRouter.Type;

public class CommandRouterTest {

    private static void expect(String text, Type type) {
        Command c = CommandRouter.parse(text);
        assertEquals("Matn: " + text, type, c == null ? null : c.type);
    }

    private static void expectApp(String text, String app) {
        Command c = CommandRouter.parse(text);
        assertEquals("Matn: " + text, Type.OPEN_APP, c == null ? null : c.type);
        assertEquals("Matn: " + text, app, c.arg);
    }

    @Test public void flashlight() {
        expect("Fonarni yoq", Type.FLASH_ON);
        expect("Jarvis, fonarni yoqib ber", Type.FLASH_ON);
        expect("chiroqni yoqing", Type.FLASH_ON);
        expect("fonarni o'chir", Type.FLASH_OFF);
        expect("fonarni o‘chirib qo‘y", Type.FLASH_OFF);
        expect("fonarni ochir", Type.FLASH_OFF);
        expect("ФОНАРНИ ЁҚ", Type.FLASH_ON);
        expect("фонарни ўчир", Type.FLASH_OFF);
        expect("fonar", Type.FLASH_TOGGLE);
    }

    @Test public void volume() {
        expect("ovozni balandlat", Type.VOLUME_UP);
        expect("ovozni ko'tar", Type.VOLUME_UP);
        expect("ovozni pasaytir", Type.VOLUME_DOWN);
        expect("ovozni o'chir", Type.VOLUME_MUTE);
        expect("ovozni maksimal qil", Type.VOLUME_MAX);
        expect("ovozni yoq", Type.VOLUME_UNMUTE);
        expect("ovozni kamaytir", Type.VOLUME_DOWN);
    }

    @Test public void connectivity() {
        expect("wifi ni yoq", Type.WIFI_PANEL);
        expect("Wi-Fi o'chir", Type.WIFI_PANEL);
        expect("vayfayni yoq", Type.WIFI_PANEL);
        expect("bluetooth ni yoq", Type.BLUETOOTH_PANEL);
        expect("blutuzni o'chir", Type.BLUETOOTH_PANEL);
    }

    @Test public void info() {
        expect("soat nechchi", Type.TIME);
        expect("hozir soat necha bo'ldi", Type.TIME);
        expect("bugun nechanchi sana", Type.DATE);
        expect("batareya necha foiz", Type.BATTERY);
        expect("zaryad qancha qoldi", Type.BATTERY);
    }

    @Test public void openApps() {
        expectApp("Telegramni och", "telegram");
        expectApp("youtube ni och", "youtube");
        expectApp("kamerani ochib ber", "kamera");
        expectApp("Jarvis, iltimos instagram ilovasini och", "instagram");
        expectApp("kalkulyatorni ishga tushir", "kalkulyator");
        expectApp("play market ni och", "play market");
        expectApp("ТЕЛЕГРАМНИ ОЧ", "telegram");
        expectApp("och telegram", "telegram");
    }

    @Test public void chatGoesToGemini() {
        assertNull(CommandRouter.parse("Salom, qalaysan?"));
        assertNull(CommandRouter.parse("Andijonda ob-havo qanday?"));
        assertNull(CommandRouter.parse("Menga biznes g'oya ber"));
        assertNull(CommandRouter.parse("ovozing chiroyli ekan"));
        assertNull(CommandRouter.parse("wifi nima degani"));
        assertNull(CommandRouter.parse(""));
        assertNull(CommandRouter.parse(null));
    }
}
