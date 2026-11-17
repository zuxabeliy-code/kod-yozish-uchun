package com.jarvis.ai.voice;

import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ResolveInfo;
import android.content.pm.ServiceInfo;
import android.os.Bundle;
import android.speech.RecognitionListener;
import android.speech.RecognitionService;
import android.speech.RecognizerIntent;
import android.speech.SpeechRecognizer;

import java.util.ArrayList;
import java.util.List;

/**
 * Android'ning o'z nutqni tanish xizmati (odatda Google) orqali o'zbekcha tinglash.
 * Faqat ASOSIY oqimda ishlating.
 *
 * Muhim: o'zbek tilini qo'llash Google ilovasi versiyasi va til paketiga bog'liq.
 * Til qo'llanmasa — onError(..., fallback=true) chaqiriladi va JARVIS server rejimiga o'tadi.
 */
public final class AndroidSpeechInput {

    public interface Listener {
        void onReady();
        void onLevel(float level01);
        void onPartial(String text);
        void onResult(String text);
        /** fallback=true — server (Gemini) orqali tanishga o'tish tavsiya etiladi. */
        void onError(String message, boolean fallback);
    }

    // API 31 konstantalari (compileSdk 30 da yo'q, shuning uchun raqam bilan)
    private static final int ERROR_TOO_MANY_REQUESTS = 10;
    private static final int ERROR_SERVER_DISCONNECTED = 11;
    private static final int ERROR_LANGUAGE_NOT_SUPPORTED = 12;
    private static final int ERROR_LANGUAGE_UNAVAILABLE = 13;

    private static final String GOOGLE_PKG = "com.google.android.googlequicksearchbox";

    private final Context ctx;
    private SpeechRecognizer recognizer;
    private Listener listener;
    private boolean active = false;

    public AndroidSpeechInput(Context context) {
        ctx = context.getApplicationContext();
    }

    public boolean isAvailable() {
        return SpeechRecognizer.isRecognitionAvailable(ctx);
    }

    /** Diagnostika uchun: qaysi tanish xizmati ishlatiladi. */
    public String describeService() {
        ComponentName c = findGoogleService();
        if (c != null) return "Google (" + c.getPackageName() + ")";
        return isAvailable() ? "Tizim standarti" : "Mavjud emas";
    }

    public boolean isActive() {
        return active;
    }

    public void start(Listener l) {
        listener = l;
        destroyRecognizer();
        // Google xizmati bo'lsa — aynan uni tanlaymiz (Samsung xizmati o'zbekchani bilmasligi mumkin).
        ComponentName google = findGoogleService();
        recognizer = google != null
                ? SpeechRecognizer.createSpeechRecognizer(ctx, google)
                : SpeechRecognizer.createSpeechRecognizer(ctx);
        recognizer.setRecognitionListener(new Callback());

        Intent i = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH);
        i.putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM);
        i.putExtra(RecognizerIntent.EXTRA_LANGUAGE, "uz-UZ");
        i.putExtra(RecognizerIntent.EXTRA_LANGUAGE_PREFERENCE, "uz-UZ");
        i.putExtra(RecognizerIntent.EXTRA_ONLY_RETURN_LANGUAGE_PREFERENCE, true);
        i.putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, true);
        i.putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 1);
        i.putExtra(RecognizerIntent.EXTRA_CALLING_PACKAGE, ctx.getPackageName());
        i.putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_COMPLETE_SILENCE_LENGTH_MILLIS, 1500);
        active = true;
        recognizer.startListening(i);
    }

    /** Tinglashni tugatib, natijani olish. */
    public void stop() {
        if (recognizer != null && active) recognizer.stopListening();
    }

    /** Natijasiz bekor qilish. */
    public void cancel() {
        active = false;
        if (recognizer != null) recognizer.cancel();
    }

    public void destroy() {
        active = false;
        destroyRecognizer();
    }

    private void destroyRecognizer() {
        if (recognizer != null) {
            try {
                recognizer.destroy();
            } catch (RuntimeException ignored) {
            }
            recognizer = null;
        }
    }

    private ComponentName findGoogleService() {
        List<ResolveInfo> list = ctx.getPackageManager()
                .queryIntentServices(new Intent(RecognitionService.SERVICE_INTERFACE), 0);
        for (ResolveInfo ri : list) {
            ServiceInfo si = ri.serviceInfo;
            if (si != null && GOOGLE_PKG.equals(si.packageName)) {
                return new ComponentName(si.packageName, si.name);
            }
        }
        return null;
    }

    private final class Callback implements RecognitionListener {
        @Override public void onReadyForSpeech(Bundle params) {
            if (listener != null) listener.onReady();
        }

        @Override public void onBeginningOfSpeech() {
        }

        @Override public void onRmsChanged(float rmsdB) {
            // Odatda -2..10 dB oralig'ida keladi
            if (listener != null) listener.onLevel(Math.max(0f, Math.min(1f, (rmsdB + 2f) / 12f)));
        }

        @Override public void onBufferReceived(byte[] buffer) {
        }

        @Override public void onEndOfSpeech() {
        }

        @Override public void onError(int error) {
            if (!active) return;
            active = false;
            if (listener == null) return;
            switch (error) {
                case SpeechRecognizer.ERROR_NO_MATCH:
                case SpeechRecognizer.ERROR_SPEECH_TIMEOUT:
                    listener.onError("Eshitmadim. Qayta gapiring.", false);
                    break;
                case SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS:
                    listener.onError("Mikrofon ruxsati yo'q.", false);
                    break;
                case SpeechRecognizer.ERROR_RECOGNIZER_BUSY:
                    listener.onError("Nutqni tanish xizmati band. Birozdan keyin urinib ko'ring.", false);
                    break;
                case SpeechRecognizer.ERROR_AUDIO:
                    listener.onError("Mikrofonda muammo (boshqa ilova band qilgan bo'lishi mumkin).", false);
                    break;
                case ERROR_LANGUAGE_NOT_SUPPORTED:
                case ERROR_LANGUAGE_UNAVAILABLE:
                    listener.onError("Android o'zbek tilini tanimayapti.", true);
                    break;
                case SpeechRecognizer.ERROR_NETWORK:
                case SpeechRecognizer.ERROR_NETWORK_TIMEOUT:
                case SpeechRecognizer.ERROR_SERVER:
                case SpeechRecognizer.ERROR_CLIENT:
                case ERROR_SERVER_DISCONNECTED:
                case ERROR_TOO_MANY_REQUESTS:
                default:
                    listener.onError("Android nutqni tanish xatosi (kod " + error + ").", true);
                    break;
            }
        }

        @Override public void onResults(Bundle results) {
            if (!active) return;
            active = false;
            String text = first(results);
            if (listener == null) return;
            if (text == null || text.trim().isEmpty()) listener.onError("Eshitmadim. Qayta gapiring.", false);
            else listener.onResult(text.trim());
        }

        @Override public void onPartialResults(Bundle partial) {
            String text = first(partial);
            if (listener != null && text != null && !text.isEmpty()) listener.onPartial(text);
        }

        @Override public void onEvent(int eventType, Bundle params) {
        }

        private String first(Bundle b) {
            if (b == null) return null;
            ArrayList<String> list = b.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION);
            return list == null || list.isEmpty() ? null : list.get(0);
        }
    }
}
