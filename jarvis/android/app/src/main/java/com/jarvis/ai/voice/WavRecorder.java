package com.jarvis.ai.voice;

import android.media.AudioFormat;
import android.media.AudioRecord;
import android.media.MediaRecorder;
import android.os.Handler;
import android.os.Looper;

import java.io.ByteArrayOutputStream;

/**
 * Mikrofondan 16 kHz mono WAV yozadi va nutq tugaganini o'zi aniqlaydi (oddiy VAD).
 * Natija serverga (Gemini) tanish uchun yuboriladi. Callback'lar asosiy oqimda keladi.
 *
 * RECORD_AUDIO ruxsati oldindan berilgan bo'lishi shart.
 */
public final class WavRecorder {

    public interface Listener {
        void onLevel(float level01);
        void onSpeechStarted();
        void onFinished(byte[] wav);
        void onNoSpeech();
        void onError(String message);
    }

    public static final int SAMPLE_RATE = 16000;
    private static final int FRAME_MS = 20;
    private static final int FRAME_SAMPLES = SAMPLE_RATE * FRAME_MS / 1000;
    private static final int CALIBRATION_MS = 300;
    private static final int SILENCE_END_MS = 1300;
    private static final int NO_SPEECH_TIMEOUT_MS = 7000;
    private static final int MAX_RECORD_MS = 15000;
    private static final int MIN_SPEECH_FRAMES = 3;

    private final Handler main = new Handler(Looper.getMainLooper());
    private volatile boolean running = false;
    private volatile boolean cancelled = false;
    private volatile boolean finishRequested = false;
    private Thread thread;

    public boolean isRunning() {
        return running;
    }

    public void start(final Listener l) {
        if (running) return;
        running = true;
        cancelled = false;
        finishRequested = false;
        thread = new Thread(new Runnable() {
            @Override public void run() {
                record(l);
            }
        }, "jarvis-wav");
        thread.start();
    }

    /** Hozircha yozilganini yakunlab yuborish. */
    public void finish() {
        finishRequested = true;
    }

    /** Natijasiz bekor qilish. */
    public void cancel() {
        cancelled = true;
        running = false;
    }

    private void record(final Listener l) {
        int minBuf = AudioRecord.getMinBufferSize(SAMPLE_RATE, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT);
        if (minBuf <= 0) {
            post(l, "Mikrofon formati qo'llanmaydi.", null);
            return;
        }
        AudioRecord rec;
        try {
            rec = new AudioRecord(MediaRecorder.AudioSource.VOICE_RECOGNITION, SAMPLE_RATE,
                    AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT, Math.max(minBuf * 2, FRAME_SAMPLES * 8));
        } catch (SecurityException | IllegalArgumentException e) {
            post(l, "Mikrofonni ochib bo'lmadi: ruxsatni tekshiring.", null);
            return;
        }
        if (rec.getState() != AudioRecord.STATE_INITIALIZED) {
            rec.release();
            post(l, "Mikrofon band yoki mavjud emas.", null);
            return;
        }

        ByteArrayOutputStream pcm = new ByteArrayOutputStream(SAMPLE_RATE * 2 * 6);
        short[] frame = new short[FRAME_SAMPLES];
        byte[] bytes = new byte[FRAME_SAMPLES * 2];
        int elapsed = 0, silenceMs = 0, loudFrames = 0, calFrames = 0;
        double noiseSum = 0, threshold = 700;
        boolean speech = false;

        try {
            rec.startRecording();
            while (running && !cancelled) {
                int n = rec.read(frame, 0, FRAME_SAMPLES);
                if (n <= 0) {
                    post(l, "Mikrofondan o'qib bo'lmadi.", null);
                    return;
                }
                for (int i = 0; i < n; i++) {
                    bytes[i * 2] = (byte) (frame[i] & 0xFF);
                    bytes[i * 2 + 1] = (byte) ((frame[i] >> 8) & 0xFF);
                }
                pcm.write(bytes, 0, n * 2);
                elapsed += FRAME_MS;

                double rms = rms(frame, n);
                final float level = toLevel(rms);
                main.post(new Runnable() {
                    @Override public void run() {
                        if (!cancelled) l.onLevel(level);
                    }
                });

                if (elapsed <= CALIBRATION_MS) {
                    noiseSum += rms;
                    calFrames++;
                    if (elapsed == CALIBRATION_MS) threshold = Math.max(700, (noiseSum / calFrames) * 2.5);
                    continue;
                }

                if (!speech) {
                    loudFrames = rms > threshold ? loudFrames + 1 : 0;
                    if (loudFrames >= MIN_SPEECH_FRAMES) {
                        speech = true;
                        main.post(new Runnable() {
                            @Override public void run() {
                                if (!cancelled) l.onSpeechStarted();
                            }
                        });
                    } else if (elapsed >= NO_SPEECH_TIMEOUT_MS && !finishRequested) {
                        main.post(new Runnable() {
                            @Override public void run() {
                                if (!cancelled) l.onNoSpeech();
                            }
                        });
                        return;
                    }
                } else {
                    silenceMs = rms < threshold * 0.7 ? silenceMs + FRAME_MS : 0;
                }

                if (finishRequested || elapsed >= MAX_RECORD_MS || (speech && silenceMs >= SILENCE_END_MS)) break;
            }
            if (cancelled) return;
            if (!speech && !finishRequested) {
                main.post(new Runnable() {
                    @Override public void run() {
                        if (!cancelled) l.onNoSpeech();
                    }
                });
                return;
            }
            final byte[] wav = toWav(pcm.toByteArray());
            main.post(new Runnable() {
                @Override public void run() {
                    if (!cancelled) l.onFinished(wav);
                }
            });
        } catch (IllegalStateException e) {
            post(l, "Mikrofon xatosi: " + e.getMessage(), null);
        } finally {
            running = false;
            try {
                rec.stop();
            } catch (IllegalStateException ignored) {
            }
            rec.release();
        }
    }

    private void post(final Listener l, final String error, final Object unused) {
        running = false;
        main.post(new Runnable() {
            @Override public void run() {
                if (!cancelled) l.onError(error);
            }
        });
    }

    private static double rms(short[] buf, int n) {
        double sum = 0;
        for (int i = 0; i < n; i++) sum += (double) buf[i] * buf[i];
        return Math.sqrt(sum / Math.max(1, n));
    }

    /** RMS → 0..1 (taxminan 35 dB … 80 dB oralig'i). */
    static float toLevel(double rms) {
        double db = 20 * Math.log10(rms + 1);
        return (float) Math.max(0, Math.min(1, (db - 35) / 45));
    }

    /** Xom PCM16 mono → WAV (44 baytli sarlavha). */
    public static byte[] toWav(byte[] pcm) {
        int byteRate = SAMPLE_RATE * 2;
        int total = 36 + pcm.length;
        byte[] h = new byte[44];
        writeStr(h, 0, "RIFF");
        writeInt(h, 4, total);
        writeStr(h, 8, "WAVE");
        writeStr(h, 12, "fmt ");
        writeInt(h, 16, 16);
        writeShort(h, 20, 1);          // PCM
        writeShort(h, 22, 1);          // mono
        writeInt(h, 24, SAMPLE_RATE);
        writeInt(h, 28, byteRate);
        writeShort(h, 32, 2);          // block align
        writeShort(h, 34, 16);         // bits
        writeStr(h, 36, "data");
        writeInt(h, 40, pcm.length);
        byte[] out = new byte[44 + pcm.length];
        System.arraycopy(h, 0, out, 0, 44);
        System.arraycopy(pcm, 0, out, 44, pcm.length);
        return out;
    }

    private static void writeStr(byte[] b, int off, String s) {
        for (int i = 0; i < 4; i++) b[off + i] = (byte) s.charAt(i);
    }

    private static void writeInt(byte[] b, int off, int v) {
        b[off] = (byte) v;
        b[off + 1] = (byte) (v >> 8);
        b[off + 2] = (byte) (v >> 16);
        b[off + 3] = (byte) (v >> 24);
    }

    private static void writeShort(byte[] b, int off, int v) {
        b[off] = (byte) v;
        b[off + 1] = (byte) (v >> 8);
    }
}
