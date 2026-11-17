package com.jarvis.ai.core;

import android.content.Context;
import android.content.SharedPreferences;

import com.jarvis.ai.security.SecurePrefs;

/** Ilova sozlamalari. Token — shifrlangan, qolganlari oddiy SharedPreferences'da. */
public final class AppSettings {

    public static final int STT_AUTO = 0;
    public static final int STT_ANDROID = 1;
    public static final int STT_SERVER = 2;

    public static final int TTS_AUTO = 0;
    public static final int TTS_ANDROID = 1;
    public static final int TTS_SERVER = 2;

    private static final String P = "jarvis_settings";
    private static final String K_URL = "server_url";
    private static final String K_TOKEN = "device_token";
    private static final String K_STT = "stt_mode";
    private static final String K_TTS = "tts_mode";
    private static final String K_RATE = "tts_rate";

    private final SharedPreferences prefs;
    private final SecurePrefs secure;

    public AppSettings(Context ctx) {
        prefs = ctx.getApplicationContext().getSharedPreferences(P, Context.MODE_PRIVATE);
        secure = new SecurePrefs(ctx);
    }

    public String getServerUrl() {
        return prefs.getString(K_URL, "");
    }

    public void setServerUrl(String url) {
        prefs.edit().putString(K_URL, normalizeUrl(url)).apply();
    }

    public String getToken() {
        String t = secure.getString(K_TOKEN);
        return t == null ? "" : t;
    }

    public void setToken(String token) {
        secure.putString(K_TOKEN, token == null ? "" : token.trim());
    }

    public boolean hasToken() {
        return !getToken().isEmpty();
    }

    public int getSttMode() {
        return prefs.getInt(K_STT, STT_AUTO);
    }

    public void setSttMode(int m) {
        prefs.edit().putInt(K_STT, m).apply();
    }

    public int getTtsMode() {
        return prefs.getInt(K_TTS, TTS_AUTO);
    }

    public void setTtsMode(int m) {
        prefs.edit().putInt(K_TTS, m).apply();
    }

    /** Android TTS tezligi: 0.5 .. 2.0 */
    public float getTtsRate() {
        return prefs.getFloat(K_RATE, 1.0f);
    }

    public void setTtsRate(float r) {
        prefs.edit().putFloat(K_RATE, r).apply();
    }

    public boolean isServerConfigured() {
        return !getServerUrl().isEmpty() && hasToken();
    }

    /** Oxiridagi "/" larni olib tashlaydi, bo'shliqlarni tozalaydi. */
    public static String normalizeUrl(String url) {
        if (url == null) return "";
        String u = url.trim();
        while (u.endsWith("/")) u = u.substring(0, u.length() - 1);
        return u;
    }

    /** Faqat https:// manzillar qabul qilinadi. */
    public static boolean isValidUrl(String url) {
        String u = normalizeUrl(url);
        return u.startsWith("https://") && u.length() > "https://x.xx".length() && !u.contains(" ");
    }

    public static boolean isValidToken(String token) {
        return token != null && token.trim().matches("[a-f0-9]{64}");
    }
}
