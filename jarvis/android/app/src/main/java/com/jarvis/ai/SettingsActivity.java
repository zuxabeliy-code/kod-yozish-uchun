package com.jarvis.ai;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.speech.SpeechRecognizer;
import android.speech.tts.TextToSpeech;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.RadioGroup;
import android.widget.SeekBar;
import android.widget.TextView;
import android.widget.Toast;

import com.jarvis.ai.core.AppSettings;
import com.jarvis.ai.net.JarvisApi;
import com.jarvis.ai.root.RootManager;

import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** Server ulanishi, ovoz rejimlari va diagnostika. */
public class SettingsActivity extends Activity {

    private AppSettings settings;
    private EditText etUrl;
    private EditText etToken;
    private TextView tvTokenState;
    private TextView tvDiag;
    private TextView tvRate;
    private RadioGroup rgStt;
    private RadioGroup rgTts;
    private SeekBar sbRate;
    private Button btnTest;
    private Button btnRoot;

    private final ExecutorService bg = Executors.newSingleThreadExecutor();
    private final Handler main = new Handler(Looper.getMainLooper());
    private TextToSpeech probeTts;
    private String ttsInfo = "Android TTS: tekshirilmoqda…";
    private String rootInfo = "ROOT: tekshirilmagan (tugmani bosing)";
    private String serverInfo = "Server: tekshirilmagan";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_settings);
        settings = new AppSettings(this);

        etUrl = findViewById(R.id.etUrl);
        etToken = findViewById(R.id.etToken);
        tvTokenState = findViewById(R.id.tvTokenState);
        tvDiag = findViewById(R.id.tvDiag);
        tvRate = findViewById(R.id.tvRate);
        rgStt = findViewById(R.id.rgStt);
        rgTts = findViewById(R.id.rgTts);
        sbRate = findViewById(R.id.sbRate);
        btnTest = findViewById(R.id.btnTest);
        btnRoot = findViewById(R.id.btnRoot);

        etUrl.setText(settings.getServerUrl());
        tvTokenState.setText(settings.hasToken()
                ? "Token saqlangan (shifrlangan). Almashtirish uchun yangisini kiriting."
                : "Token kiritilmagan.");

        rgStt.check(new int[]{R.id.sttAuto, R.id.sttAndroid, R.id.sttServer}[clamp(settings.getSttMode())]);
        rgTts.check(new int[]{R.id.ttsAuto, R.id.ttsAndroid, R.id.ttsServer}[clamp(settings.getTtsMode())]);

        // 0..15 → 0.5..2.0
        sbRate.setProgress(Math.round((settings.getTtsRate() - 0.5f) * 10f));
        updateRateLabel();
        sbRate.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override public void onProgressChanged(SeekBar s, int p, boolean fromUser) {
                updateRateLabel();
            }

            @Override public void onStartTrackingTouch(SeekBar s) {
            }

            @Override public void onStopTrackingTouch(SeekBar s) {
            }
        });

        findViewById(R.id.btnBack).setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                finish();
            }
        });
        findViewById(R.id.btnSave).setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                if (save()) finish();
            }
        });
        btnTest.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                if (save()) testConnection();
            }
        });
        btnRoot.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                checkRoot();
            }
        });
        findViewById(R.id.btnTtsSettings).setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                try {
                    startActivity(new Intent("com.android.settings.TTS_SETTINGS"));
                } catch (ActivityNotFoundException e) {
                    toast("TTS sozlamalari topilmadi");
                }
            }
        });

        probeTts();
        renderDiag();
    }

    @Override
    protected void onDestroy() {
        bg.shutdownNow();
        if (probeTts != null) probeTts.shutdown();
        super.onDestroy();
    }

    private boolean save() {
        String url = AppSettings.normalizeUrl(etUrl.getText().toString());
        String token = etToken.getText().toString().trim();

        if (!url.isEmpty() && !AppSettings.isValidUrl(url)) {
            etUrl.setError("Manzil https:// bilan boshlanishi kerak");
            return false;
        }
        if (!token.isEmpty() && !AppSettings.isValidToken(token)) {
            etToken.setError("Token 64 ta belgidan (0-9, a-f) iborat bo'lishi kerak");
            return false;
        }
        settings.setServerUrl(url);
        if (!token.isEmpty()) {
            try {
                settings.setToken(token);
            } catch (IllegalStateException e) {
                toast("Tokenni xavfsiz saqlab bo'lmadi");
                return false;
            }
            etToken.setText("");
            tvTokenState.setText("Token saqlangan (shifrlangan).");
        }

        int stt = rgStt.getCheckedRadioButtonId();
        settings.setSttMode(stt == R.id.sttAndroid ? AppSettings.STT_ANDROID
                : stt == R.id.sttServer ? AppSettings.STT_SERVER : AppSettings.STT_AUTO);
        int tts = rgTts.getCheckedRadioButtonId();
        settings.setTtsMode(tts == R.id.ttsAndroid ? AppSettings.TTS_ANDROID
                : tts == R.id.ttsServer ? AppSettings.TTS_SERVER : AppSettings.TTS_AUTO);
        settings.setTtsRate(0.5f + sbRate.getProgress() / 10f);
        toast("Saqlandi");
        return true;
    }

    private void testConnection() {
        if (!settings.isServerConfigured()) {
            serverInfo = "Server: manzil yoki token kiritilmagan";
            renderDiag();
            return;
        }
        btnTest.setEnabled(false);
        serverInfo = "Server: tekshirilmoqda…";
        renderDiag();
        final JarvisApi api = new JarvisApi(settings.getServerUrl(), settings.getToken());
        bg.execute(new Runnable() {
            @Override public void run() {
                String res;
                try {
                    res = "✔ " + api.ping();
                } catch (JarvisApi.ApiException e) {
                    res = "✘ " + e.getMessage();
                }
                final String r = res;
                main.post(new Runnable() {
                    @Override public void run() {
                        if (isDestroyed()) return;
                        btnTest.setEnabled(true);
                        serverInfo = r;
                        renderDiag();
                    }
                });
            }
        });
    }

    private void checkRoot() {
        btnRoot.setEnabled(false);
        rootInfo = "ROOT: tekshirilmoqda… (Magisk oynasi chiqsa, ruxsat bering)";
        renderDiag();
        bg.execute(new Runnable() {
            @Override public void run() {
                final RootManager.Status s = RootManager.check();
                main.post(new Runnable() {
                    @Override public void run() {
                        if (isDestroyed()) return;
                        btnRoot.setEnabled(true);
                        rootInfo = RootManager.describe(s);
                        renderDiag();
                    }
                });
            }
        });
    }

    private void probeTts() {
        probeTts = new TextToSpeech(this, new TextToSpeech.OnInitListener() {
            @Override public void onInit(int status) {
                if (status != TextToSpeech.SUCCESS) {
                    ttsInfo = "Android TTS: ishga tushmadi";
                } else {
                    boolean uz = probeTts.isLanguageAvailable(new Locale("uz", "UZ")) >= TextToSpeech.LANG_AVAILABLE;
                    ttsInfo = "Android TTS: " + probeTts.getDefaultEngine()
                            + (uz ? "\n  o'zbek ovozi: BOR ✔" : "\n  o'zbek ovozi: YO'Q → server TTS ishlatiladi");
                }
                main.post(new Runnable() {
                    @Override public void run() {
                        if (!isDestroyed()) renderDiag();
                    }
                });
            }
        });
    }

    private void renderDiag() {
        String stt = SpeechRecognizer.isRecognitionAvailable(this)
                ? "Android nutqni tanish: mavjud" : "Android nutqni tanish: YO'Q → server ishlatiladi";
        tvDiag.setText(serverInfo + "\n\n" + stt + "\n" + ttsInfo + "\n\n" + rootInfo
                + "\n\nIlova: v" + BuildConfig.VERSION_NAME + " · Android " + android.os.Build.VERSION.RELEASE);
    }

    private void updateRateLabel() {
        tvRate.setText(String.format(Locale.ROOT, "Android TTS tezligi: %.1fx", 0.5f + sbRate.getProgress() / 10f));
    }

    private static int clamp(int i) {
        return Math.max(0, Math.min(2, i));
    }

    private void toast(String s) {
        Toast.makeText(this, s, Toast.LENGTH_SHORT).show();
    }
}
