package com.jarvis.ai.ui;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.RadialGradient;
import android.graphics.RectF;
import android.graphics.Shader;
import android.os.SystemClock;
import android.util.AttributeSet;
import android.view.View;

/**
 * JARVIS animatsiyali yadrosi. To'liq Canvas'da chiziladi (tashqi kutubxonasiz, apparat tezlatish bilan).
 *
 * Holatlar:
 *  IDLE      — sekin aylanuvchi halqalar, yengil "nafas olish".
 *  LISTENING — mikrofon balandligiga javob beruvchi radial chiziqlar.
 *  THINKING  — tez aylanuvchi segmentlar, binafsha-ko'k aksent.
 *  SPEAKING  — yadrodan tarqaluvchi to'lqinlar.
 *  ERROR     — qizil rangda qisqa signal.
 */
public class CoreView extends View {

    public enum State { IDLE, LISTENING, THINKING, SPEAKING, ERROR }

    private static final int CYAN = 0xFF00E5FF;
    private static final int BLUE = 0xFF2979FF;
    private static final int VIOLET = 0xFF7C4DFF;
    private static final int RED = 0xFFFF3D57;
    private static final int BARS = 72;

    private final Paint ring = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint glow = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint fill = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint tick = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final RectF oval = new RectF();
    private final float[] barLevels = new float[BARS];

    private State state = State.IDLE;
    private float targetLevel = 0f;   // 0..1 mikrofon/ovoz darajasi
    private float level = 0f;         // silliqlangan qiymat
    private float colorMix = 0f;      // 0 = ko'k, 1 = holat rangi
    private int accent = CYAN;
    private long lastFrame = 0;
    private float t = 0f;             // animatsiya vaqti (sekund)
    private float speed = 1f;
    private float density;

    public CoreView(Context context) {
        super(context);
        init();
    }

    public CoreView(Context context, AttributeSet attrs) {
        super(context, attrs);
        init();
    }

    private void init() {
        density = getResources().getDisplayMetrics().density;
        ring.setStyle(Paint.Style.STROKE);
        ring.setStrokeCap(Paint.Cap.ROUND);
        glow.setStyle(Paint.Style.STROKE);
        glow.setStrokeCap(Paint.Cap.ROUND);
        tick.setStyle(Paint.Style.STROKE);
        tick.setStrokeCap(Paint.Cap.ROUND);
        fill.setStyle(Paint.Style.FILL);
    }

    public void setState(State s) {
        if (s == null || s == state) return;
        state = s;
        if (s != State.LISTENING && s != State.SPEAKING) targetLevel = 0f;
        invalidate();
    }

    public State getState() {
        return state;
    }

    /** 0..1 oralig'ida ovoz balandligi (mikrofon RMS). */
    public void setLevel(float l) {
        targetLevel = Math.max(0f, Math.min(1f, l));
    }

    @Override
    protected void onDraw(Canvas c) {
        long now = SystemClock.uptimeMillis();
        float dt = lastFrame == 0 ? 0.016f : Math.min(0.05f, (now - lastFrame) / 1000f);
        lastFrame = now;

        // Holatga mos tezlik va rang
        float targetSpeed;
        int targetAccent;
        switch (state) {
            case LISTENING: targetSpeed = 1.6f; targetAccent = CYAN; break;
            case THINKING:  targetSpeed = 4.2f; targetAccent = VIOLET; break;
            case SPEAKING:  targetSpeed = 2.0f; targetAccent = CYAN; break;
            case ERROR:     targetSpeed = 0.6f; targetAccent = RED; break;
            default:        targetSpeed = 0.7f; targetAccent = CYAN; break;
        }
        speed += (targetSpeed - speed) * Math.min(1f, dt * 3f);
        accent = targetAccent;
        colorMix += ((state == State.IDLE ? 0f : 1f) - colorMix) * Math.min(1f, dt * 4f);
        t += dt * speed;

        // Ovoz darajasini silliqlash. Gapirishda sintetik "nutq" ritmi.
        float wanted = targetLevel;
        if (state == State.SPEAKING) {
            wanted = 0.35f + 0.3f * (float) Math.abs(Math.sin(t * 3.1)) * (0.6f + 0.4f * (float) Math.sin(t * 7.3));
        }
        level += (wanted - level) * Math.min(1f, dt * 10f);

        float w = getWidth(), h = getHeight();
        float cx = w / 2f, cy = h / 2f;
        float R = Math.min(w, h) * 0.36f;
        int main = blend(BLUE, accent, colorMix);

        drawBackgroundGlow(c, cx, cy, R, main);
        drawTicks(c, cx, cy, R * 1.18f, main);
        drawRings(c, cx, cy, R, main);
        drawBars(c, cx, cy, R * 0.62f, main);
        drawCore(c, cx, cy, R * 0.34f, main);

        postInvalidateOnAnimation();
    }

    private void drawBackgroundGlow(Canvas c, float cx, float cy, float R, int color) {
        float r = R * (1.45f + level * 0.25f);
        fill.setShader(new RadialGradient(cx, cy, r,
                new int[]{withAlpha(color, 70), withAlpha(color, 18), 0x00000000},
                new float[]{0f, 0.55f, 1f}, Shader.TileMode.CLAMP));
        c.drawCircle(cx, cy, r, fill);
        fill.setShader(null);
    }

    /** Tashqi shkala: 120 ta chiziqcha, sekin aylanadi. */
    private void drawTicks(Canvas c, float cx, float cy, float r, int color) {
        tick.setStrokeWidth(1.2f * density);
        float rot = -t * 6f;
        for (int i = 0; i < 120; i++) {
            double a = Math.toRadians(i * 3 + rot);
            boolean major = i % 10 == 0;
            float len = (major ? 10f : 4f) * density;
            tick.setColor(withAlpha(color, major ? 200 : 70));
            float x1 = cx + (float) Math.cos(a) * r, y1 = cy + (float) Math.sin(a) * r;
            float x2 = cx + (float) Math.cos(a) * (r - len), y2 = cy + (float) Math.sin(a) * (r - len);
            c.drawLine(x1, y1, x2, y2, tick);
        }
    }

    /** Uchta segmentli halqa, har biri o'z tezligi va yo'nalishi bilan. */
    private void drawRings(Canvas c, float cx, float cy, float R, int color) {
        float pulse = 1f + level * 0.08f;
        arcSet(c, cx, cy, R * 1.0f * pulse, 3.0f, t * 40f, new float[]{0, 70, 110, 40, 200, 95}, color, 230);
        arcSet(c, cx, cy, R * 0.86f * pulse, 2.0f, -t * 65f, new float[]{20, 120, 180, 60, 270, 50}, BLUE, 200);
        arcSet(c, cx, cy, R * 0.74f, 1.4f, t * 110f, new float[]{0, 30, 90, 30, 180, 30, 270, 30}, color, 150);
    }

    private void arcSet(Canvas c, float cx, float cy, float r, float strokeDp, float rotation,
                        float[] segments, int color, int alpha) {
        oval.set(cx - r, cy - r, cx + r, cy + r);
        glow.setStrokeWidth(strokeDp * density * 4.5f);
        glow.setColor(withAlpha(color, alpha / 7));
        ring.setStrokeWidth(strokeDp * density);
        ring.setColor(withAlpha(color, alpha));
        for (int i = 0; i + 1 < segments.length; i += 2) {
            float start = segments[i] + rotation;
            c.drawArc(oval, start, segments[i + 1], false, glow);
            c.drawArc(oval, start, segments[i + 1], false, ring);
        }
    }

    /** Radial "ekvalayzer": tinglash va gapirishda jonlanadi. */
    private void drawBars(Canvas c, float cx, float cy, float r, int color) {
        boolean active = state == State.LISTENING || state == State.SPEAKING;
        tick.setStrokeWidth(2.2f * density);
        for (int i = 0; i < BARS; i++) {
            float noise = (float) (0.5 + 0.5 * Math.sin(i * 0.9 + t * 5.0) * Math.cos(i * 0.37 - t * 3.3));
            float target = active ? level * (0.35f + 0.65f * noise) : 0.04f + 0.03f * noise;
            barLevels[i] += (target - barLevels[i]) * 0.25f;
            float len = (4f + barLevels[i] * 34f) * density;
            double a = Math.toRadians(i * (360.0 / BARS) - 90);
            float cos = (float) Math.cos(a), sin = (float) Math.sin(a);
            tick.setColor(withAlpha(color, (int) (90 + 160 * Math.min(1f, barLevels[i] * 1.5f))));
            c.drawLine(cx + cos * r, cy + sin * r, cx + cos * (r + len), cy + sin * (r + len), tick);
        }
    }

    /** Markaziy yorqin yadro. */
    private void drawCore(Canvas c, float cx, float cy, float r, int color) {
        float breathe = 1f + 0.05f * (float) Math.sin(t * 2.2) + level * 0.25f;
        float rr = r * breathe;
        fill.setShader(new RadialGradient(cx, cy, rr,
                new int[]{0xFFFFFFFF, withAlpha(color, 255), withAlpha(color, 90), 0x00000000},
                new float[]{0f, 0.25f, 0.6f, 1f}, Shader.TileMode.CLAMP));
        c.drawCircle(cx, cy, rr, fill);
        fill.setShader(null);

        ring.setStrokeWidth(1.5f * density);
        ring.setColor(withAlpha(color, 220));
        c.drawCircle(cx, cy, r * 1.15f, ring);

        if (state == State.SPEAKING || state == State.THINKING) {
            // Tarqaluvchi to'lqin
            float phase = (t * 0.6f) % 1f;
            ring.setColor(withAlpha(color, (int) (200 * (1f - phase))));
            c.drawCircle(cx, cy, r * (1.15f + phase * 1.6f), ring);
        }
    }

    private static int withAlpha(int color, int alpha) {
        return (Math.max(0, Math.min(255, alpha)) << 24) | (color & 0x00FFFFFF);
    }

    private static int blend(int a, int b, float f) {
        f = Math.max(0f, Math.min(1f, f));
        int r = (int) (Color.red(a) + (Color.red(b) - Color.red(a)) * f);
        int g = (int) (Color.green(a) + (Color.green(b) - Color.green(a)) * f);
        int bl = (int) (Color.blue(a) + (Color.blue(b) - Color.blue(a)) * f);
        return Color.rgb(r, g, bl);
    }
}
