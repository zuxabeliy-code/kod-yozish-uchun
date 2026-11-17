package com.jarvis.ai.commands;

import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.pm.ResolveInfo;
import android.hardware.camera2.CameraAccessException;
import android.hardware.camera2.CameraCharacteristics;
import android.hardware.camera2.CameraManager;
import android.media.AudioManager;
import android.os.BatteryManager;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

/**
 * Lokal buyruqlarni bajaradi. Faqat oddiy ilovaga ruxsat etilgan Android API'lari ishlatiladi.
 * Har bir natija halol: qila olmaydigan ishni "bajarildi" demaydi.
 */
public final class DeviceActions {

    public static final class Result {
        public final String speech;
        public final boolean ok;

        Result(String speech, boolean ok) {
            this.speech = speech;
            this.ok = ok;
        }
    }

    private static final String[] MONTHS = {"yanvar", "fevral", "mart", "aprel", "may", "iyun",
            "iyul", "avgust", "sentabr", "oktabr", "noyabr", "dekabr"};
    private static final String[] WEEKDAYS = {"yakshanba", "dushanba", "seshanba", "chorshanba",
            "payshanba", "juma", "shanba"};

    private final Context ctx;
    private final CameraManager cameraManager;
    private final AudioManager audio;
    private String torchCameraId;
    private boolean torchOn = false;

    private final CameraManager.TorchCallback torchCallback = new CameraManager.TorchCallback() {
        @Override
        public void onTorchModeChanged(String cameraId, boolean enabled) {
            if (cameraId.equals(torchCameraId)) torchOn = enabled;
        }
    };

    public DeviceActions(Context context) {
        ctx = context.getApplicationContext();
        cameraManager = (CameraManager) ctx.getSystemService(Context.CAMERA_SERVICE);
        audio = (AudioManager) ctx.getSystemService(Context.AUDIO_SERVICE);
        torchCameraId = findTorchCamera();
        if (cameraManager != null && torchCameraId != null) {
            cameraManager.registerTorchCallback(torchCallback, new Handler(Looper.getMainLooper()));
        }
    }

    public void release() {
        if (cameraManager != null) cameraManager.unregisterTorchCallback(torchCallback);
    }

    /** Asosiy oqimda chaqiring (Activity kontekstidan ilovalarni ochish uchun). */
    public Result execute(CommandRouter.Command cmd, Context activityContext) {
        switch (cmd.type) {
            case FLASH_ON: return setTorch(true);
            case FLASH_OFF: return setTorch(false);
            case FLASH_TOGGLE: return setTorch(!torchOn);
            case VOLUME_UP: return volume(AudioManager.ADJUST_RAISE, 2, "Ovoz balandlatildi");
            case VOLUME_DOWN: return volume(AudioManager.ADJUST_LOWER, 2, "Ovoz pasaytirildi");
            case VOLUME_MUTE: return volume(AudioManager.ADJUST_MUTE, 1, "Ovoz o'chirildi");
            case VOLUME_UNMUTE: return volume(AudioManager.ADJUST_UNMUTE, 1, "Ovoz yoqildi");
            case VOLUME_MAX: return volumeMax();
            case WIFI_PANEL: return openWifiPanel(activityContext);
            case BLUETOOTH_PANEL: return openSettings(activityContext, new Intent(Settings.ACTION_BLUETOOTH_SETTINGS),
                    "Bluetooth sozlamalarini ochdim. Android 12 da ilovalar Bluetooth'ni o'zi yoqa olmaydi, shu yerdan yoqing.");
            case TIME: return new Result("Hozir soat " + new SimpleDateFormat("HH:mm", Locale.ROOT).format(new Date()), true);
            case DATE: return new Result(today(), true);
            case BATTERY: return battery();
            case OPEN_APP: return openApp(cmd.arg, activityContext);
            default: return new Result("Bu buyruqni hali bajara olmayman.", false);
        }
    }

    // --- Fonar ---

    private String findTorchCamera() {
        if (cameraManager == null) return null;
        try {
            for (String id : cameraManager.getCameraIdList()) {
                CameraCharacteristics ch = cameraManager.getCameraCharacteristics(id);
                Boolean flash = ch.get(CameraCharacteristics.FLASH_INFO_AVAILABLE);
                Integer facing = ch.get(CameraCharacteristics.LENS_FACING);
                if (Boolean.TRUE.equals(flash) && facing != null && facing == CameraCharacteristics.LENS_FACING_BACK) {
                    return id;
                }
            }
        } catch (CameraAccessException | RuntimeException ignored) {
            // kamera xizmati band yoki mavjud emas
        }
        return null;
    }

    private Result setTorch(boolean on) {
        if (torchCameraId == null) return new Result("Bu qurilmada fonar topilmadi.", false);
        try {
            cameraManager.setTorchMode(torchCameraId, on);
            torchOn = on;
            return new Result(on ? "Fonar yoqildi" : "Fonar o'chirildi", true);
        } catch (CameraAccessException e) {
            return new Result("Fonarni boshqarib bo'lmadi: kamera boshqa ilovada band bo'lishi mumkin.", false);
        } catch (RuntimeException e) {
            return new Result("Fonarni boshqarib bo'lmadi.", false);
        }
    }

    // --- Ovoz ---

    private Result volume(int direction, int steps, String msg) {
        if (audio == null) return new Result("Ovoz xizmati topilmadi.", false);
        try {
            for (int i = 0; i < steps; i++) {
                audio.adjustStreamVolume(AudioManager.STREAM_MUSIC, direction,
                        i == steps - 1 ? AudioManager.FLAG_SHOW_UI : 0);
            }
            return new Result(msg + ". Daraja: " + volumePercent() + " foiz.", true);
        } catch (SecurityException e) {
            // "Bezovta qilmaslik" rejimida ba'zi o'zgarishlar taqiqlanadi
            return new Result("Ovozni o'zgartirishga tizim ruxsat bermadi (Bezovta qilmaslik rejimi yoqilgan bo'lishi mumkin).", false);
        }
    }

    private Result volumeMax() {
        if (audio == null) return new Result("Ovoz xizmati topilmadi.", false);
        try {
            int max = audio.getStreamMaxVolume(AudioManager.STREAM_MUSIC);
            audio.setStreamVolume(AudioManager.STREAM_MUSIC, max, AudioManager.FLAG_SHOW_UI);
            return new Result("Ovoz maksimal darajaga qo'yildi.", true);
        } catch (SecurityException e) {
            return new Result("Ovozni o'zgartirishga tizim ruxsat bermadi.", false);
        }
    }

    private int volumePercent() {
        int max = audio.getStreamMaxVolume(AudioManager.STREAM_MUSIC);
        int cur = audio.getStreamVolume(AudioManager.STREAM_MUSIC);
        return max <= 0 ? 0 : Math.round(cur * 100f / max);
    }

    // --- Wi-Fi / Bluetooth ---

    private Result openWifiPanel(Context activity) {
        Intent i = Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q
                ? new Intent(Settings.Panel.ACTION_WIFI)
                : new Intent(Settings.ACTION_WIFI_SETTINGS);
        return openSettings(activity, i,
                "Wi-Fi panelini ochdim. Android 10 dan boshlab ilovalar Wi-Fi'ni o'zi yoqa olmaydi, shu paneldan yoqing.");
    }

    private Result openSettings(Context activity, Intent intent, String msg) {
        try {
            if (!(activity instanceof android.app.Activity)) intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            activity.startActivity(intent);
            return new Result(msg, true);
        } catch (ActivityNotFoundException e) {
            return new Result("Sozlamalar oynasini ochib bo'lmadi.", false);
        }
    }

    // --- Ma'lumot ---

    private static String today() {
        java.util.Calendar c = java.util.Calendar.getInstance();
        return "Bugun " + c.get(java.util.Calendar.DAY_OF_MONTH) + "-" + MONTHS[c.get(java.util.Calendar.MONTH)]
                + ", " + WEEKDAYS[c.get(java.util.Calendar.DAY_OF_WEEK) - 1] + ".";
    }

    private Result battery() {
        BatteryManager bm = (BatteryManager) ctx.getSystemService(Context.BATTERY_SERVICE);
        if (bm == null) return new Result("Batareya ma'lumotini olib bo'lmadi.", false);
        int pct = bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY);
        boolean charging = bm.isCharging();
        return new Result("Batareya " + pct + " foiz" + (charging ? ", zaryadlanmoqda." : "."), true);
    }

    // --- Ilovalar ---

    private Result openApp(String name, Context activity) {
        PackageManager pm = ctx.getPackageManager();
        Intent main = new Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER);
        List<ResolveInfo> apps = pm.queryIntentActivities(main, 0);
        List<String> labels = new ArrayList<>(apps.size());
        for (ResolveInfo ri : apps) labels.add(String.valueOf(ri.loadLabel(pm)));

        int idx = AppMatcher.bestMatch(name, labels);
        if (idx < 0) return new Result("\"" + name + "\" nomli ilova topilmadi.", false);

        ResolveInfo ri = apps.get(idx);
        if (ctx.getPackageName().equals(ri.activityInfo.packageName)) {
            return new Result("JARVIS allaqachon ochiq.", true);
        }
        Intent launch = pm.getLaunchIntentForPackage(ri.activityInfo.packageName);
        if (launch == null) return new Result(labels.get(idx) + " ilovasini ochib bo'lmadi.", false);
        try {
            if (!(activity instanceof android.app.Activity)) launch.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            activity.startActivity(launch);
            return new Result(labels.get(idx) + " ochilmoqda.", true);
        } catch (ActivityNotFoundException | SecurityException e) {
            return new Result(labels.get(idx) + " ilovasini ochib bo'lmadi.", false);
        }
    }
}
