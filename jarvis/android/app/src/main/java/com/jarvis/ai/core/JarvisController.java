package com.jarvis.ai.core;

import android.app.Activity;
import android.os.Handler;
import android.os.Looper;

import com.jarvis.ai.commands.CommandRouter;
import com.jarvis.ai.commands.DeviceActions;
import com.jarvis.ai.net.JarvisApi;
import com.jarvis.ai.ui.CoreView;
import com.jarvis.ai.voice.AndroidSpeechInput;
import com.jarvis.ai.voice.SpeechOutput;
import com.jarvis.ai.voice.WavRecorder;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * JARVIS "miyasi": tinglash → tanish → buyruq yoki Gemini → ovozli javob.
 * Faqat asosiy oqimda chaqiriladi; tarmoq ishlari fon oqimida bajariladi.
 */
public final class JarvisController {

    /** UI qatlami (MainActivity) shu interfeysni amalga oshiradi. */
    public interface Ui {
        void showState(CoreView.State state, String label);
        void showLevel(float level);
        void addMessage(boolean fromUser, String text);
        void showPartial(String text);
        void showSystem(String text);
        void requestMicPermission();
        boolean hasMicPermission();
    }

    private static final int MAX_HISTORY = 20;

    private final Activity activity;
    private final Ui ui;
    private final AppSettings settings;
    private final AndroidSpeechInput androidStt;
    private final WavRecorder recorder = new WavRecorder();
    private final SpeechOutput speech;
    private final DeviceActions actions;
    private final ExecutorService net = Executors.newSingleThreadExecutor();
    private final Handler main = new Handler(Looper.getMainLooper());
    private final List<JarvisApi.Turn> history = new ArrayList<>();

    /** Har yangi so'rovda oshadi — eski javoblar e'tiborsiz qoladi. */
    private int requestId = 0;
    /** Android o'zbekchani tanimasa, sessiya davomida server rejimida qolamiz. */
    private boolean androidSttFailed = false;
    private boolean busy = false;

    public JarvisController(Activity activity, Ui ui) {
        this.activity = activity;
        this.ui = ui;
        this.settings = new AppSettings(activity);
        this.androidStt = new AndroidSpeechInput(activity);
        this.speech = new SpeechOutput(activity, settings);
        this.actions = new DeviceActions(activity);
    }

    public AppSettings settings() {
        return settings;
    }

    // ------------------------------------------------------------------ Kirish nuqtalari

    /** Mikrofon tugmasi: tinglashni boshlash yoki to'xtatish. */
    public void onMicPressed() {
        if (speech.isSpeaking()) {
            speech.stop();
            idle();
            return;
        }
        if (androidStt.isActive()) {
            androidStt.stop();
            return;
        }
        if (recorder.isRunning()) {
            recorder.finish();
            return;
        }
        if (busy) {
            cancelAll();
            return;
        }
        if (!ui.hasMicPermission()) {
            ui.requestMicPermission();
            return;
        }
        startListening();
    }

    public void onTextSubmitted(String text) {
        if (text == null || text.trim().isEmpty()) return;
        cancelAll();
        handleUserText(text.trim());
    }

    /** Ilova fonga o'tganda: tinglashni to'xtatamiz (ovozli javob davom etishi mumkin). */
    public void onBackground() {
        if (androidStt.isActive()) androidStt.cancel();
        if (recorder.isRunning()) recorder.cancel();
        if (!speech.isSpeaking() && !busy) idle();
    }

    public void release() {
        cancelAll();
        androidStt.destroy();
        speech.shutdown();
        actions.release();
        net.shutdownNow();
    }

    public String diagnostics() {
        return "Nutqni tanish: " + androidStt.describeService()
                + (androidSttFailed ? " (bu sessiyada o'zbekchani tanimadi → server)" : "")
                + "\n" + speech.describe()
                + "\nServer: " + (settings.isServerConfigured() ? settings.getServerUrl() : "sozlanmagan");
    }

    public boolean isAndroidUzTtsAvailable() {
        return speech.isAndroidUzAvailable();
    }

    // ------------------------------------------------------------------ Tinglash

    private void startListening() {
        speech.stop();
        int mode = settings.getSttMode();
        boolean server = mode == AppSettings.STT_SERVER
                || (mode == AppSettings.STT_AUTO && (androidSttFailed || !androidStt.isAvailable()));
        if (server) {
            if (!settings.isServerConfigured()) {
                say("Server sozlanmagan. Sozlamalarda server manzili va tokenni kiriting.", false);
                return;
            }
            startServerListening();
        } else {
            startAndroidListening();
        }
    }

    private void startAndroidListening() {
        ui.showState(CoreView.State.LISTENING, "Eshityapman…");
        androidStt.start(new AndroidSpeechInput.Listener() {
            @Override public void onReady() {
                ui.showState(CoreView.State.LISTENING, "Gapiring…");
            }

            @Override public void onLevel(float level01) {
                ui.showLevel(level01);
            }

            @Override public void onPartial(String text) {
                ui.showPartial(text);
            }

            @Override public void onResult(String text) {
                ui.showPartial(null);
                handleUserText(text);
            }

            @Override public void onError(String message, boolean fallback) {
                ui.showPartial(null);
                if (fallback && settings.getSttMode() == AppSettings.STT_AUTO && settings.isServerConfigured()) {
                    androidSttFailed = true;
                    ui.showSystem(message + " Server (Gemini) orqali tinglayapman.");
                    startServerListening();
                } else {
                    error(message);
                }
            }
        });
    }

    private void startServerListening() {
        ui.showState(CoreView.State.LISTENING, "Gapiring… (tugatish uchun bosing)");
        recorder.start(new WavRecorder.Listener() {
            @Override public void onLevel(float level01) {
                ui.showLevel(level01);
            }

            @Override public void onSpeechStarted() {
                ui.showState(CoreView.State.LISTENING, "Eshityapman…");
            }

            @Override public void onFinished(byte[] wav) {
                transcribe(wav);
            }

            @Override public void onNoSpeech() {
                error("Ovoz eshitilmadi. Qayta urinib ko'ring.");
            }

            @Override public void onError(String message) {
                error(message);
            }
        });
    }

    private void transcribe(final byte[] wav) {
        final int id = ++requestId;
        busy = true;
        ui.showState(CoreView.State.THINKING, "Nutq tanilmoqda…");
        final JarvisApi api = api();
        net.execute(new Runnable() {
            @Override public void run() {
                try {
                    final String text = api.transcribe(wav);
                    post(id, new Runnable() {
                        @Override public void run() {
                            busy = false;
                            if (text.isEmpty()) error("Nutq aniqlanmadi. Qayta gapiring.");
                            else handleUserText(text);
                        }
                    });
                } catch (final JarvisApi.ApiException e) {
                    post(id, new Runnable() {
                        @Override public void run() {
                            busy = false;
                            error(e.getMessage());
                        }
                    });
                }
            }
        });
    }

    // ------------------------------------------------------------------ Qayta ishlash

    private void handleUserText(final String text) {
        ui.addMessage(true, text);

        CommandRouter.Command cmd = CommandRouter.parse(text);
        if (cmd != null) {
            DeviceActions.Result r = actions.execute(cmd, activity);
            remember(text, r.speech);
            say(r.speech, r.ok);
            return;
        }

        if (!settings.isServerConfigured()) {
            say("Gemini bilan suhbat uchun server sozlanmagan. Sozlamalarga kiring.", false);
            return;
        }

        final int id = ++requestId;
        busy = true;
        ui.showState(CoreView.State.THINKING, "O'ylayapman…");
        final JarvisApi api = api();
        final List<JarvisApi.Turn> snapshot = new ArrayList<>(history);
        net.execute(new Runnable() {
            @Override public void run() {
                try {
                    final String reply = api.chat(text, snapshot);
                    post(id, new Runnable() {
                        @Override public void run() {
                            busy = false;
                            remember(text, reply);
                            say(reply, true);
                        }
                    });
                } catch (final JarvisApi.ApiException e) {
                    post(id, new Runnable() {
                        @Override public void run() {
                            busy = false;
                            say(e.getMessage(), false);
                        }
                    });
                }
            }
        });
    }

    private void say(String text, final boolean ok) {
        ui.addMessage(false, text);
        ui.showState(CoreView.State.SPEAKING, "Gapiryapman…");
        speech.speak(text, settings.isServerConfigured() ? api() : null, new SpeechOutput.Callback() {
            @Override public void onStart(String engine) {
            }

            @Override public void onDone() {
                if (ok) idle();
                else flashError();
            }

            @Override public void onError(String message) {
                ui.showSystem("Ovoz: " + message);
                idle();
            }
        });
    }

    private void remember(String user, String reply) {
        history.add(new JarvisApi.Turn("user", user));
        history.add(new JarvisApi.Turn("model", reply));
        while (history.size() > MAX_HISTORY) history.remove(0);
    }

    // ------------------------------------------------------------------ Yordamchi

    private JarvisApi api() {
        return new JarvisApi(settings.getServerUrl(), settings.getToken());
    }

    private void post(final int id, final Runnable r) {
        main.post(new Runnable() {
            @Override public void run() {
                if (id == requestId && !activity.isDestroyed()) r.run();
            }
        });
    }

    private void cancelAll() {
        requestId++;
        busy = false;
        if (androidStt.isActive()) androidStt.cancel();
        if (recorder.isRunning()) recorder.cancel();
        speech.stop();
        ui.showPartial(null);
        idle();
    }

    private void idle() {
        ui.showLevel(0f);
        ui.showState(CoreView.State.IDLE, "Tayyor. Mikrofonni bosing");
    }

    private void error(String message) {
        ui.showSystem(message);
        flashError();
    }

    private void flashError() {
        ui.showState(CoreView.State.ERROR, "Xato");
        main.postDelayed(new Runnable() {
            @Override public void run() {
                if (!busy && !speech.isSpeaking() && !androidStt.isActive() && !recorder.isRunning()) idle();
            }
        }, 1500);
    }
}
