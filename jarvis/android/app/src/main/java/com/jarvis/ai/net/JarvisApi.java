package com.jarvis.ai.net;

import android.util.Base64;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.SocketTimeoutException;
import java.net.URL;
import java.net.UnknownHostException;
import java.nio.charset.StandardCharsets;
import java.util.List;

import javax.net.ssl.SSLException;

/**
 * JARVIS backend bilan aloqa. Gemini kaliti ilovada YO'Q — ilova faqat o'z serverimizga
 * qurilma tokeni bilan murojaat qiladi. Barcha metodlar BLOKLOVCHI: fon oqimida chaqiring.
 */
public final class JarvisApi {

    /** Suhbat tarixi elementi. role: "user" yoki "model". */
    public static final class Turn {
        public final String role;
        public final String text;

        public Turn(String role, String text) {
            this.role = role;
            this.text = text;
        }
    }

    public static final class ApiException extends Exception {
        public final int httpCode;
        public final String code;

        public ApiException(int httpCode, String code, String message) {
            super(message);
            this.httpCode = httpCode;
            this.code = code;
        }
    }

    private static final int CONNECT_TIMEOUT = 15_000;
    private static final int READ_TIMEOUT = 90_000;
    private static final int MAX_RESPONSE = 8 * 1024 * 1024;

    private final String baseUrl;
    private final String token;

    public JarvisApi(String baseUrl, String token) {
        this.baseUrl = baseUrl;
        this.token = token;
    }

    /** Server va token tekshiruvi. Natija: qurilma nomi va server versiyasi. */
    public String ping() throws ApiException {
        JSONObject r = parseJson(request("ping", "GET", null));
        return "Server: " + r.optString("server") + " v" + r.optString("version")
                + "\nQurilma: " + r.optString("device");
    }

    public String chat(String message, List<Turn> history) throws ApiException {
        try {
            JSONObject body = new JSONObject();
            body.put("message", message);
            JSONArray h = new JSONArray();
            for (Turn t : history) {
                h.put(new JSONObject().put("role", t.role).put("text", t.text));
            }
            body.put("history", h);
            return parseJson(request("chat", "POST", body.toString())).optString("reply", "").trim();
        } catch (JSONException e) {
            throw new ApiException(0, "json", "So'rov tayyorlashda xato");
        }
    }

    public String transcribe(byte[] wav) throws ApiException {
        try {
            JSONObject body = new JSONObject();
            body.put("mime", "audio/wav");
            body.put("audio", Base64.encodeToString(wav, Base64.NO_WRAP));
            return parseJson(request("stt", "POST", body.toString())).optString("text", "").trim();
        } catch (JSONException e) {
            throw new ApiException(0, "json", "So'rov tayyorlashda xato");
        }
    }

    /** Natija: WAV baytlar. */
    public byte[] tts(String text) throws ApiException {
        try {
            JSONObject body = new JSONObject().put("text", text);
            Response r = request("tts", "POST", body.toString());
            if (r.contentType == null || !r.contentType.startsWith("audio/")) {
                throw new ApiException(r.code, "bad_audio", "Server audio qaytarmadi");
            }
            return r.body;
        } catch (JSONException e) {
            throw new ApiException(0, "json", "So'rov tayyorlashda xato");
        }
    }

    // ---------------------------------------------------------------------------------------

    private static final class Response {
        int code;
        String contentType;
        byte[] body;
    }

    private Response request(String action, String method, String json) throws ApiException {
        HttpURLConnection conn = null;
        try {
            URL url = new URL(baseUrl + "/api/?a=" + action);
            if (!"https".equalsIgnoreCase(url.getProtocol())) {
                throw new ApiException(0, "https_only", "Faqat https:// manzil ruxsat etiladi");
            }
            conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod(method);
            conn.setConnectTimeout(CONNECT_TIMEOUT);
            conn.setReadTimeout(READ_TIMEOUT);
            conn.setUseCaches(false);
            conn.setInstanceFollowRedirects(false);
            conn.setRequestProperty("X-Jarvis-Token", token);
            conn.setRequestProperty("Accept", "application/json, audio/wav");
            conn.setRequestProperty("User-Agent", "JARVIS-Android/1.0");
            if (json != null) {
                byte[] out = json.getBytes(StandardCharsets.UTF_8);
                conn.setDoOutput(true);
                conn.setFixedLengthStreamingMode(out.length);
                conn.setRequestProperty("Content-Type", "application/json; charset=utf-8");
                try (OutputStream os = conn.getOutputStream()) {
                    os.write(out);
                }
            }
            Response r = new Response();
            r.code = conn.getResponseCode();
            r.contentType = conn.getContentType();
            InputStream is = r.code >= 400 ? conn.getErrorStream() : conn.getInputStream();
            r.body = is == null ? new byte[0] : readAll(is);

            if (r.code >= 300 && r.code < 400) {
                throw new ApiException(r.code, "redirect",
                        "Server boshqa manzilga yo'naltiryapti. Manzilni tekshiring (oxirida / bo'lmasin, https bo'lsin).");
            }
            if (r.code != 200) {
                String msg = defaultMessage(r.code);
                String code = "http_" + r.code;
                try {
                    JSONObject e = new JSONObject(new String(r.body, StandardCharsets.UTF_8));
                    msg = e.optString("message", msg);
                    code = e.optString("error", code);
                } catch (JSONException ignored) {
                    // JSON emas (masalan hosting xato sahifasi)
                }
                throw new ApiException(r.code, code, msg);
            }
            return r;
        } catch (ApiException e) {
            throw e;
        } catch (UnknownHostException e) {
            throw new ApiException(0, "no_host", "Serverga ulanib bo'lmadi: internet yoki manzilni tekshiring");
        } catch (SocketTimeoutException e) {
            throw new ApiException(0, "timeout", "Server javob bermadi (vaqt tugadi)");
        } catch (SSLException e) {
            throw new ApiException(0, "ssl", "SSL sertifikat xatosi. Saytda haqiqiy HTTPS sertifikat bo'lishi kerak");
        } catch (IOException e) {
            throw new ApiException(0, "io", "Tarmoq xatosi: " + e.getMessage());
        } finally {
            if (conn != null) conn.disconnect();
        }
    }

    private static String defaultMessage(int code) {
        switch (code) {
            case 401: return "Token noto'g'ri yoki o'chirilgan";
            case 404: return "API topilmadi. Server manzilini tekshiring";
            case 429: return "Limit tugadi, biroz kuting";
            case 500: return "Server xatosi";
            case 502:
            case 504: return "Gemini bilan aloqa xatosi";
            case 503: return "Server o'rnatilmagan";
            default: return "Server xatosi (" + code + ")";
        }
    }

    private static JSONObject parseJson(Response r) throws ApiException {
        try {
            JSONObject o = new JSONObject(new String(r.body, StandardCharsets.UTF_8));
            if (!o.optBoolean("ok", false)) {
                throw new ApiException(r.code, o.optString("error", "fail"), o.optString("message", "Noma'lum xato"));
            }
            return o;
        } catch (JSONException e) {
            throw new ApiException(r.code, "bad_json", "Server javobi noto'g'ri formatda");
        }
    }

    private static byte[] readAll(InputStream is) throws IOException {
        try (InputStream in = is; ByteArrayOutputStream bos = new ByteArrayOutputStream()) {
            byte[] buf = new byte[16 * 1024];
            int n;
            while ((n = in.read(buf)) != -1) {
                bos.write(buf, 0, n);
                if (bos.size() > MAX_RESPONSE) throw new IOException("Javob juda katta");
            }
            return bos.toByteArray();
        }
    }
}
