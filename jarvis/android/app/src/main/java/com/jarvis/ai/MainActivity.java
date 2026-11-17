package com.jarvis.ai;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.View;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputMethodManager;
import android.widget.EditText;
import android.widget.ImageButton;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import com.jarvis.ai.core.JarvisController;
import com.jarvis.ai.ui.CoreView;

/** Asosiy ekran: AI yadrosi, suhbat oynasi, mikrofon va matn kiritish. */
public class MainActivity extends Activity implements JarvisController.Ui {

    private static final int REQ_MIC = 101;
    private static final int MAX_MESSAGES = 60;

    private CoreView core;
    private TextView stateLabel;
    private TextView statusText;
    private View statusDot;
    private LinearLayout chatList;
    private ScrollView chatScroll;
    private EditText input;
    private ImageButton btnMic;
    private TextView partialView;

    private JarvisController jarvis;
    private boolean greeted = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        core = findViewById(R.id.coreView);
        stateLabel = findViewById(R.id.stateLabel);
        statusText = findViewById(R.id.statusText);
        statusDot = findViewById(R.id.statusDot);
        chatList = findViewById(R.id.chatList);
        chatScroll = findViewById(R.id.chatScroll);
        input = findViewById(R.id.input);
        btnMic = findViewById(R.id.btnMic);

        GradientDrawable dot = new GradientDrawable();
        dot.setShape(GradientDrawable.OVAL);
        dot.setColor(getColor(R.color.jv_muted));
        statusDot.setBackground(dot);

        jarvis = new JarvisController(this, this);

        btnMic.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                jarvis.onMicPressed();
            }
        });
        core.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                jarvis.onMicPressed();
            }
        });
        findViewById(R.id.btnSend).setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                submitText();
            }
        });
        input.setOnEditorActionListener(new TextView.OnEditorActionListener() {
            @Override public boolean onEditorAction(TextView v, int actionId, KeyEvent event) {
                if (actionId == EditorInfo.IME_ACTION_SEND
                        || (event != null && event.getKeyCode() == KeyEvent.KEYCODE_ENTER && event.getAction() == KeyEvent.ACTION_DOWN)) {
                    submitText();
                    return true;
                }
                return false;
            }
        });
        findViewById(R.id.btnSettings).setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                startActivity(new Intent(MainActivity.this, SettingsActivity.class));
            }
        });
    }

    @Override
    protected void onResume() {
        super.onResume();
        refreshStatus();
        if (!greeted) {
            greeted = true;
            addMessage(false, jarvis.settings().isServerConfigured()
                    ? "Salom, Zuxa. JARVIS tayyor. Mikrofonni bosing yoki yozing."
                    : "Salom, Zuxa. Avval Sozlamalarda server manzili va tokenni kiriting. Lokal buyruqlar (fonar, ovoz, ilovalar) hozir ham ishlaydi.");
        }
    }

    @Override
    protected void onStop() {
        super.onStop();
        jarvis.onBackground();
    }

    @Override
    protected void onDestroy() {
        jarvis.release();
        super.onDestroy();
    }

    private void submitText() {
        String text = input.getText().toString().trim();
        if (text.isEmpty()) return;
        input.setText("");
        InputMethodManager imm = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
        if (imm != null) imm.hideSoftInputFromWindow(input.getWindowToken(), 0);
        jarvis.onTextSubmitted(text);
    }

    private void refreshStatus() {
        boolean ok = jarvis.settings().isServerConfigured();
        statusText.setText(ok ? "ONLAYN · GEMINI ULANGAN" : "OFLAYN · SERVER SOZLANMAGAN");
        statusText.setTextColor(getColor(ok ? R.color.jv_ok : R.color.jv_muted));
        ((GradientDrawable) statusDot.getBackground()).setColor(getColor(ok ? R.color.jv_ok : R.color.jv_error));
    }

    // ------------------------------------------------------------------ JarvisController.Ui

    @Override
    public void showState(CoreView.State state, String label) {
        core.setState(state);
        stateLabel.setText(label);
        stateLabel.setTextColor(getColor(state == CoreView.State.ERROR ? R.color.jv_error : R.color.jv_cyan));
        boolean active = state == CoreView.State.LISTENING || state == CoreView.State.SPEAKING
                || state == CoreView.State.THINKING;
        btnMic.setActivated(active);
        btnMic.setImageResource(active ? R.drawable.ic_stop : R.drawable.ic_mic);
    }

    @Override
    public void showLevel(float level) {
        core.setLevel(level);
    }

    @Override
    public void addMessage(boolean fromUser, String text) {
        TextView tv = bubble(fromUser);
        tv.setText(text);
        chatList.addView(tv);
        trimAndScroll();
    }

    @Override
    public void showPartial(String text) {
        if (text == null) {
            if (partialView != null) {
                chatList.removeView(partialView);
                partialView = null;
            }
            return;
        }
        if (partialView == null) {
            partialView = bubble(true);
            partialView.setAlpha(0.6f);
            chatList.addView(partialView);
        }
        partialView.setText(text + " …");
        trimAndScroll();
    }

    @Override
    public void showSystem(String text) {
        TextView tv = new TextView(this);
        tv.setText("— " + text + " —");
        tv.setTextColor(getColor(R.color.jv_muted));
        tv.setTextSize(TypedValue.COMPLEX_UNIT_SP, 12);
        tv.setGravity(Gravity.CENTER);
        tv.setPadding(0, dp(4), 0, dp(4));
        chatList.addView(tv);
        trimAndScroll();
    }

    @Override
    public void requestMicPermission() {
        requestPermissions(new String[]{Manifest.permission.RECORD_AUDIO}, REQ_MIC);
    }

    @Override
    public boolean hasMicPermission() {
        return checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED;
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode != REQ_MIC) return;
        if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
            jarvis.onMicPressed();
        } else {
            showSystem("Mikrofon ruxsati berilmadi. Ovozli buyruqlar uchun ruxsat kerak (Sozlamalar → Ilovalar → JARVIS → Ruxsatlar).");
        }
    }

    // ------------------------------------------------------------------ Yordamchi

    private TextView bubble(boolean fromUser) {
        TextView tv = new TextView(this);
        tv.setTextColor(getColor(fromUser ? R.color.jv_text : R.color.jv_cyan));
        tv.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        tv.setLineSpacing(dp(2), 1f);
        tv.setTextIsSelectable(true);
        tv.setBackgroundResource(fromUser ? R.drawable.bg_bubble_user : R.drawable.bg_bubble_ai);
        tv.setPadding(dp(14), dp(10), dp(14), dp(10));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        lp.gravity = fromUser ? Gravity.END : Gravity.START;
        lp.topMargin = dp(5);
        lp.bottomMargin = dp(5);
        if (fromUser) lp.leftMargin = dp(48);
        else lp.rightMargin = dp(48);
        tv.setLayoutParams(lp);
        return tv;
    }

    private void trimAndScroll() {
        while (chatList.getChildCount() > MAX_MESSAGES) {
            View first = chatList.getChildAt(0);
            if (first == partialView) break;
            chatList.removeViewAt(0);
        }
        chatScroll.post(new Runnable() {
            @Override public void run() {
                chatScroll.smoothScrollTo(0, chatList.getBottom());
            }
        });
    }

    private int dp(int v) {
        return Math.round(v * getResources().getDisplayMetrics().density);
    }
}
