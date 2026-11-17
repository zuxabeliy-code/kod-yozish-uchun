package com.jarvis.ai.voice;

import android.content.Context;
import android.media.AudioAttributes;
import android.media.AudioFocusRequest;
import android.media.AudioManager;
import android.media.MediaPlayer;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.speech.tts.TextToSpeech;
import android.speech.tts.UtteranceProgressListener;
import android.util.Log;

import com.jarvis.ai.core.AppSettings;
import com.jarvis.ai.net.JarvisApi;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * Ovozli javob.
 *  - Android TTS: o'zbek ovozi o'rnatilgan bo'lsa (tez, internetsiz).
 *  - Server TTS: backend orqali Gemini TTS (o'zbekcha, internet kerak).
 * Avto rejimda o'zbek Android ovozi bo'lmasa server ishlatiladi; server xato bersa — Android TTS'ga qaytadi.
 */
public final class SpeechOutput {

    public interface Callback {
        void onStart(String engine);
        void onDone();
        void onError(String message);
    }

    private static final String TAG = "JarvisTTS";
    private static final Locale UZ = new Locale("uz", "UZ");

    private final Context ctx;
    private final AppSettings settings;
    private final Handler main = new Handler(Looper.getMainLooper());
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AudioManager audioManager;

    private TextToSpeech tts;
    private boolean ttsReady = false;
    private boolean androidUz = false;
    private MediaPlayer player;
    private AudioFocusRequest focusRequest;
    private int generation = 0;      // eski so'rov natijalarini e'tiborsiz qoldirish uchun
    private Callback current;

    public SpeechOutput(Context context, AppSettings appSettings) {
        ctx = context.getApplicationContext();
        settings = appSettings;
        audioManager = (AudioManager) ctx.getSystemService(Context.AUDIO_SERVICE);
        tts = new TextToSpeech(ctx, new TextToSpeech.OnInitListener() {
            @Override public void onInit(int status) {
                ttsReady = status == TextToSpeech.SUCCESS;
                if (!ttsReady) return;
                int r = tts.isLanguageAvailable(UZ);
                androidUz = r >= TextToSpeech.LANG_AVAILABLE;
                tts.setLanguage(androidUz ? UZ : Locale.getDefault());
                tts.setOnUtteranceProgressListener(new Progress());
            }
        });
    }

    public boolean isAndroidUzAvailable() {
        return androidUz;
    }

    public String describe() {
        if (!ttsReady) return "Android TTS: tayyor emas";
        return "Android TTS: " + tts.getDefaultEngine() + (androidUz ? " (o'zbek ovozi BOR)" : " (o'zbek ovozi YO'Q)");
    }

    public boolean isSpeaking() {
        return current != null;
    }

    public void speak(String rawText, JarvisApi api, Callback cb) {
        stop();
        final String text = clean(rawText);
        if (text.isEmpty()) {
            cb.onDone();
            return;
        }
        current = cb;
        final int gen = ++generation;
        int mode = settings.getTtsMode();
        boolean useServer = mode == AppSettings.TTS_SERVER
                || (mode == AppSettings.TTS_AUTO && !androidUz);
        if (useServer && api != null) {
            speakServer(text, api, gen);
        } else if (useServer) {
            // Server sozlanmagan — Android bilan aytamiz
            speakAndroid(text, gen);
        } else {
            speakAndroid(text, gen);
        }
    }

    public void stop() {
        generation++;
        if (tts != null && ttsReady) tts.stop();
        releasePlayer();
        abandonFocus();
        current = null;
    }

    public void shutdown() {
        stop();
        io.shutdownNow();
        if (tts != null) tts.shutdown();
    }

    // --- Android TTS ---

    private void speakAndroid(String text, int gen) {
        if (!ttsReady) {
            finishError(gen, "Ovoz dvigateli tayyor emas");
            return;
        }
        requestFocus();
        tts.setSpeechRate(settings.getTtsRate());
        Bundle params = new Bundle();
        params.putInt(TextToSpeech.Engine.KEY_PARAM_STREAM, AudioManager.STREAM_MUSIC);
        int max = TextToSpeech.getMaxSpeechInputLength();
        String t = text.length() > max ? text.substring(0, max) : text;
        int r = tts.speak(t, TextToSpeech.QUEUE_FLUSH, params, "jv-" + gen);
        if (r != TextToSpeech.SUCCESS) finishError(gen, "Android TTS xatosi");
        else if (current != null) current.onStart(androidUz ? "android-uz" : "android");
    }

    private final class Progress extends UtteranceProgressListener {
        @Override public void onStart(String id) {
        }

        @Override public void onDone(final String id) {
            main.post(new Runnable() {
                @Override public void run() {
                    if (("jv-" + generation).equals(id)) finishOk(generation);
                }
            });
        }

        @Override public void onError(final String id) {
            main.post(new Runnable() {
                @Override public void run() {
                    if (("jv-" + generation).equals(id)) finishError(generation, "Android TTS xatosi");
                }
            });
        }
    }

    // --- Server (Gemini) TTS ---

    private void speakServer(final String text, final JarvisApi api, final int gen) {
        io.execute(new Runnable() {
            @Override public void run() {
                try {
                    byte[] wav = api.tts(text.length() > 1500 ? text.substring(0, 1500) : text);
                    final File f = new File(ctx.getCacheDir(), "jarvis_tts.wav");
                    try (FileOutputStream fos = new FileOutputStream(f)) {
                        fos.write(wav);
                    }
                    main.post(new Runnable() {
                        @Override public void run() {
                            if (gen == generation) play(f, text, gen);
                        }
                    });
                } catch (final JarvisApi.ApiException | IOException e) {
                    Log.w(TAG, "Server TTS xatosi", e);
                    main.post(new Runnable() {
                        @Override public void run() {
                            if (gen != generation) return;
                            // Zaxira: Android TTS (o'zbekcha bo'lmasa ham matn o'qiladi)
                            speakAndroid(text, gen);
                        }
                    });
                }
            }
        });
    }

    private void play(File f, final String text, final int gen) {
        releasePlayer();
        requestFocus();
        try {
            player = new MediaPlayer();
            player.setAudioAttributes(new AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ASSISTANT)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                    .build());
            player.setDataSource(f.getAbsolutePath());
            player.setOnCompletionListener(new MediaPlayer.OnCompletionListener() {
                @Override public void onCompletion(MediaPlayer mp) {
                    if (gen == generation) finishOk(gen);
                }
            });
            player.setOnErrorListener(new MediaPlayer.OnErrorListener() {
                @Override public boolean onError(MediaPlayer mp, int what, int extra) {
                    if (gen == generation) {
                        releasePlayer();
                        speakAndroid(text, gen);
                    }
                    return true;
                }
            });
            player.prepare();
            player.start();
            if (current != null) current.onStart("gemini");
        } catch (IOException | RuntimeException e) {
            releasePlayer();
            speakAndroid(text, gen);
        }
    }

    private void releasePlayer() {
        if (player != null) {
            try {
                player.stop();
            } catch (IllegalStateException ignored) {
            }
            player.release();
            player = null;
        }
    }

    // --- Audio fokus: boshqa ilova musiqasini vaqtincha pasaytiradi ---

    private void requestFocus() {
        if (audioManager == null) return;
        focusRequest = new AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_MAY_DUCK)
                .setAudioAttributes(new AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_ASSISTANT)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build())
                .build();
        audioManager.requestAudioFocus(focusRequest);
    }

    private void abandonFocus() {
        if (audioManager != null && focusRequest != null) {
            audioManager.abandonAudioFocusRequest(focusRequest);
            focusRequest = null;
        }
    }

    private void finishOk(int gen) {
        if (gen != generation) return;
        Callback cb = current;
        current = null;
        releasePlayer();
        abandonFocus();
        if (cb != null) cb.onDone();
    }

    private void finishError(int gen, String msg) {
        if (gen != generation) return;
        Callback cb = current;
        current = null;
        abandonFocus();
        if (cb != null) cb.onError(msg);
    }

    /** Ovoz uchun keraksiz belgilarni olib tashlash (markdown, emoji). */
    static String clean(String s) {
        if (s == null) return "";
        return s.replaceAll("[*#_`~>|]", " ")
                .replaceAll("[\\x{1F300}-\\x{1FAFF}\\x{2600}-\\x{27BF}]", " ")
                .replaceAll("\\s+", " ")
                .trim();
    }
}
