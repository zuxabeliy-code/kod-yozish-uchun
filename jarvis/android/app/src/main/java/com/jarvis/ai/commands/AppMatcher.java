package com.jarvis.ai.commands;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

/**
 * Aytilgan ilova nomini telefondagi ilovalar ro'yxati bilan solishtiradi.
 * O'zbekcha nomlar (kamera, galereya, sozlamalar...) inglizcha/ruscha nomlarga moslanadi.
 * Toza Java — unit-test qilinadi.
 */
public final class AppMatcher {

    private static final Map<String, String[]> ALIASES = new HashMap<>();

    static {
        alias(new String[]{"kamera", "foto", "fotoapparat"}, "camera", "камера", "kamera");
        alias(new String[]{"galereya", "galeriya", "rasmlar", "suratlar"}, "gallery", "photos", "галерея", "google photos");
        alias(new String[]{"sozlamalar", "sozlama", "nastroyka", "nastroyki"}, "settings", "настройки");
        alias(new String[]{"kalkulyator", "kalkulator", "hisoblagich"}, "calculator", "калькулятор");
        alias(new String[]{"soat", "budilnik"}, "clock", "часы", "будильник");
        alias(new String[]{"kontaktlar", "kontakt"}, "contacts", "контакты");
        alias(new String[]{"xabarlar", "sms", "smslar"}, "messages", "сообщения");
        alias(new String[]{"telefon", "qo'ng'iroq", "qongiroq"}, "phone", "телефон");
        alias(new String[]{"brauzer", "internet", "xrom", "chrom"}, "chrome", "samsung internet", "internet");
        alias(new String[]{"xarita", "karta"}, "maps", "карты", "google maps", "yandex maps");
        alias(new String[]{"musiqa", "muzika"}, "music", "youtube music", "samsung music", "музыка");
        alias(new String[]{"kalendar", "taqvim"}, "calendar", "календарь");
        alias(new String[]{"eslatma", "eslatmalar", "zametka", "zametki", "notes"}, "samsung notes", "notes", "keep", "заметки");
        alias(new String[]{"fayllar", "fayl", "fayl menejer"}, "my files", "files", "мои файлы");
        alias(new String[]{"yutub", "yutup", "youtub", "ютуб"}, "youtube");
        alias(new String[]{"telegram", "telegramm", "tg"}, "telegram");
        alias(new String[]{"instagram", "insta", "instagramm"}, "instagram");
        alias(new String[]{"vatsap", "vatsapp", "whatsapp", "votsap"}, "whatsapp");
        alias(new String[]{"play market", "pley market", "plei market", "market", "play store"}, "play store", "google play", "play маркет");
        alias(new String[]{"pochta", "gmail", "email"}, "gmail", "email", "почта");
        alias(new String[]{"ob-havo", "ob havo", "pogoda"}, "weather", "погода");
        alias(new String[]{"diktofon", "ovoz yozish"}, "voice recorder", "диктофон");
    }

    private static void alias(String[] keys, String... targets) {
        for (String k : keys) ALIASES.put(k, targets);
    }

    private AppMatcher() {
    }

    /** Eng mos ilova indeksini qaytaradi yoki topilmasa -1. */
    public static int bestMatch(String spokenName, List<String> labels) {
        if (spokenName == null || labels == null || labels.isEmpty()) return -1;
        String q = spokenName.toLowerCase(Locale.ROOT).trim();

        List<String> queries = new ArrayList<>();
        queries.add(q);
        String[] al = ALIASES.get(q);
        if (al != null) {
            for (String a : al) queries.add(a);
        }

        int best = -1;
        int bestScore = 0;
        for (int i = 0; i < labels.size(); i++) {
            String label = clean(labels.get(i));
            if (label.isEmpty()) continue;
            for (int k = 0; k < queries.size(); k++) {
                String query = clean(queries.get(k));
                if (query.isEmpty()) continue;
                int s = score(query, label);
                if (k > 0) s -= 1; // aynan aytilgan nom taxallusdan biroz ustun
                if (s > bestScore) {
                    bestScore = s;
                    best = i;
                }
            }
        }
        return bestScore >= 60 ? best : -1;
    }

    static int score(String q, String label) {
        if (label.equals(q)) return 100;
        if (label.startsWith(q) && q.length() >= 3) return 90 - Math.min(20, label.length() - q.length());
        if (label.contains(q) && q.length() >= 4) return 80 - Math.min(20, label.length() - q.length());
        if (q.contains(label) && label.length() >= 4) return 70;
        int maxLen = Math.max(q.length(), label.length());
        if (maxLen < 4) return 0;
        double sim = 1.0 - (double) levenshtein(q, label) / maxLen;
        return sim >= 0.75 ? (int) (sim * 85) : 0;
    }

    static String clean(String s) {
        return s.toLowerCase(Locale.ROOT).replaceAll("[^\\p{L}\\p{N}]", "");
    }

    static int levenshtein(String a, String b) {
        int[] prev = new int[b.length() + 1];
        int[] cur = new int[b.length() + 1];
        for (int j = 0; j <= b.length(); j++) prev[j] = j;
        for (int i = 1; i <= a.length(); i++) {
            cur[0] = i;
            for (int j = 1; j <= b.length(); j++) {
                int cost = a.charAt(i - 1) == b.charAt(j - 1) ? 0 : 1;
                cur[j] = Math.min(Math.min(cur[j - 1] + 1, prev[j] + 1), prev[j - 1] + cost);
            }
            int[] t = prev;
            prev = cur;
            cur = t;
        }
        return prev[b.length()];
    }
}
