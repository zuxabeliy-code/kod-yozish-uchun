package com.jarvis.ai.commands;

import org.junit.Test;

import java.util.Arrays;
import java.util.List;

import static org.junit.Assert.assertEquals;

public class AppMatcherTest {

    private static final List<String> LABELS = Arrays.asList(
            "Telegram", "YouTube", "Camera", "Gallery", "Settings", "Calculator", "Clock",
            "Chrome", "Instagram", "WhatsApp", "Play Store", "My Files", "Samsung Notes", "Phone", "Messages",
            "YouTube Music", "Калькулятор");

    private static String match(String q) {
        int i = AppMatcher.bestMatch(q, LABELS);
        return i < 0 ? null : LABELS.get(i);
    }

    @Test public void exactAndAliases() {
        assertEquals("Telegram", match("telegram"));
        assertEquals("Telegram", match("telegramm"));
        assertEquals("YouTube", match("youtube"));
        assertEquals("YouTube", match("yutub"));
        assertEquals("Camera", match("kamera"));
        assertEquals("Gallery", match("galereya"));
        assertEquals("Settings", match("sozlamalar"));
        assertEquals("Calculator", match("kalkulyator"));
        assertEquals("Play Store", match("play market"));
        assertEquals("Chrome", match("brauzer"));
        assertEquals("WhatsApp", match("vatsap"));
        assertEquals("Samsung Notes", match("eslatma"));
        assertEquals("YouTube Music", match("youtube music"));
    }

    @Test public void unknown() {
        assertEquals(null, match("eshik"));
        assertEquals(null, match("xyzabc"));
    }
}
