package com.jarvis.ai.commands;

import java.util.Locale;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * O'zbekcha ovozli buyruqlarni lokal (internetsiz, tez) aniqlaydi.
 * Buyruq topilmasa null qaytadi va matn Gemini'ga suhbat sifatida yuboriladi.
 *
 * Toza Java: Android'ga bog'liq emas, shuning uchun unit-testlar bilan tekshiriladi.
 */
public final class CommandRouter {

    public enum Type {
        FLASH_ON, FLASH_OFF, FLASH_TOGGLE,
        VOLUME_UP, VOLUME_DOWN, VOLUME_MUTE, VOLUME_UNMUTE, VOLUME_MAX,
        WIFI_PANEL, BLUETOOTH_PANEL,
        TIME, DATE, BATTERY,
        OPEN_APP
    }

    public static final class Command {
        public final Type type;
        public final String arg;

        Command(Type type, String arg) {
            this.type = type;
            this.arg = arg;
        }

        @Override
        public String toString() {
            return type + (arg != null ? "(" + arg + ")" : "");
        }
    }

    // --- Lug'at ---
    private static final Pattern FLASH = Pattern.compile("\\b(fonar\\w*|fanar\\w*|chiroq\\w*|flesh\\w*|flash\\w*|фонар\\w*)");
    private static final Pattern TURN_OFF = Pattern.compile("\\b(o'?chir\\w*|o'?chirib\\w*)");
    private static final Pattern TURN_ON = Pattern.compile("\\b(yoq(ib|ing|gin|il\\w*|sang|ib ber\\w*)?|yondir\\w*|on)\\b");

    private static final Pattern VOLUME = Pattern.compile("\\b(ovoz(ni|i|ini)?|zvuk\\w*|gromkost\\w*|tovush\\w*)\\b");
    private static final Pattern VOL_MAX = Pattern.compile("\\b(maksimal\\w*|maksimum\\w*|eng baland\\w*|to'?liq)\\b");
    private static final Pattern VOL_UP = Pattern.compile("\\b(baland\\w*|ko'?tar\\w*|oshir\\w*|kuchaytir\\w*|qo'?sh\\w*)\\b");
    private static final Pattern VOL_DOWN = Pattern.compile("\\b(past\\w*|pasaytir\\w*|kamaytir\\w*|tushir\\w*|sekinlat\\w*)\\b");
    private static final Pattern VOL_MUTE = Pattern.compile("\\b(o'?chir\\w*|jim\\w*|ovozsiz\\w*)\\b");

    private static final Pattern WIFI = Pattern.compile("(wi-?fi|vay ?fay|vayfay|wifi|вайфай)");
    private static final Pattern BT = Pattern.compile("(bluetooth|blutuz\\w*|blyutuz\\w*|blutus\\w*|блютуз\\w*)");
    private static final Pattern SETTING_ACTION = Pattern.compile("\\b(yoq\\w*|o'?chir\\w*|och\\w*|sozla\\w*|ulan\\w*|ula\\w*|panel\\w*|ko'?rsat\\w*)\\b");

    private static final Pattern TIME = Pattern.compile("(soat nech\\w*|vaqt nech\\w*|hozir soat|soat qancha|vaqt qancha|hozir vaqt)");
    private static final Pattern DATE = Pattern.compile("(bugun nech\\w*|bugun qaysi kun|bugun qanaqa kun|bugun qanday kun|sana\\b|bugungi sana|bugun sana)");
    private static final Pattern BATTERY = Pattern.compile("\\b(batareya\\w*|zaryad\\w*|akkumulyator\\w*)\\b");

    /** "och", "ochib ber", "ochgin", "ochinglar", "ishga tushir" — lekin "o'chir"/"ochir" emas. */
    private static final Pattern OPEN_VERB = Pattern.compile(
            "\\b(och(ib( ber\\w*| qo'?y\\w*)?|gin|ing\\w*|sang|ilsin|ish)?|ishga tushir\\w*|zapusti|otkroy)\\b");

    private static final Pattern NAME_NOISE = Pattern.compile(
            "\\b(jarvis|jarvisjon|iltimos|menga|bir|ilovasi(ni)?|ilova(ni|si)?|dasturi(ni)?|dastur(ni)?|programma(ni)?|prilojeniye)\\b");

    private CommandRouter() {
    }

    public static Command parse(String raw) {
        if (raw == null) return null;
        String s = normalize(raw);
        if (s.isEmpty()) return null;

        // 1) Fonar
        if (FLASH.matcher(s).find()) {
            if (TURN_OFF.matcher(s).find()) return new Command(Type.FLASH_OFF, null);
            if (TURN_ON.matcher(s).find()) return new Command(Type.FLASH_ON, null);
            return new Command(Type.FLASH_TOGGLE, null);
        }

        // 2) Ovoz balandligi
        if (VOLUME.matcher(s).find()) {
            if (VOL_MAX.matcher(s).find()) return new Command(Type.VOLUME_MAX, null);
            if (VOL_MUTE.matcher(s).find()) return new Command(Type.VOLUME_MUTE, null);
            if (VOL_DOWN.matcher(s).find()) return new Command(Type.VOLUME_DOWN, null);
            if (VOL_UP.matcher(s).find()) return new Command(Type.VOLUME_UP, null);
            if (TURN_ON.matcher(s).find()) return new Command(Type.VOLUME_UNMUTE, null);
            // "ovoz" so'zi bor, lekin harakat yo'q — oddiy suhbat bo'lishi mumkin.
        }

        // 3) Wi-Fi / Bluetooth (Android 12 da faqat sozlamalar paneli)
        if (WIFI.matcher(s).find() && SETTING_ACTION.matcher(s).find()) return new Command(Type.WIFI_PANEL, null);
        if (BT.matcher(s).find() && SETTING_ACTION.matcher(s).find()) return new Command(Type.BLUETOOTH_PANEL, null);

        // 4) Vaqt, sana, batareya
        if (TIME.matcher(s).find()) return new Command(Type.TIME, null);
        if (DATE.matcher(s).find()) return new Command(Type.DATE, null);
        if (BATTERY.matcher(s).find()
                && Pattern.compile("\\b(nech\\w*|qancha|foiz\\w*|holat\\w*|qoldi|bormi)\\b").matcher(s).find()) {
            return new Command(Type.BATTERY, null);
        }

        // 5) Ilovani ochish
        Matcher m = OPEN_VERB.matcher(s);
        if (m.find()) {
            String before = s.substring(0, m.start()).trim();
            String after = s.substring(m.end()).trim();
            String name = cleanAppName(before.isEmpty() ? after : before);
            if (!name.isEmpty()) return new Command(Type.OPEN_APP, name);
        }
        return null;
    }

    /** "telegramni" → "telegram", "youtube ni" → "youtube", "kamera ilovasini" → "kamera". */
    static String cleanAppName(String s) {
        String n = NAME_NOISE.matcher(s).replaceAll(" ");
        n = n.replaceAll("[^\\p{L}\\p{N}' +]", " ").replaceAll("\\s+", " ").trim();
        // alohida turgan "ni" qo'shimchasi
        n = n.replaceAll("(\\s|-)n?i$", "").trim();
        // so'zga yopishgan qo'shimcha: telegramni, kamerani, youtubeni
        if (n.length() > 4 && n.endsWith("ni")) n = n.substring(0, n.length() - 2);
        if (n.endsWith("'")) n = n.substring(0, n.length() - 1);
        return n.trim();
    }

    /** Kichik harf, kirill → lotin, apostroflarni birxillashtirish, tinish belgilarini olib tashlash. */
    public static String normalize(String raw) {
        String s = transliterate(raw.toLowerCase(Locale.ROOT));
        s = s.replaceAll("[‘’ʻʼ`´]", "'");
        s = s.replaceAll("[.,!?;:\"«»()\\[\\]]", " ");
        s = s.replaceAll("^\\s*(jarvis|jarvisjon)\\b", " ");
        return s.replaceAll("\\s+", " ").trim();
    }

    private static final String[][] CYR = {
            {"ё", "yo"}, {"ю", "yu"}, {"я", "ya"}, {"ш", "sh"}, {"щ", "sh"}, {"ч", "ch"}, {"ц", "ts"},
            {"ў", "o'"}, {"ғ", "g'"}, {"қ", "q"}, {"ҳ", "h"}, {"ж", "j"}, {"х", "x"}, {"ъ", "'"}, {"ь", ""},
            {"а", "a"}, {"б", "b"}, {"в", "v"}, {"г", "g"}, {"д", "d"}, {"е", "e"}, {"з", "z"}, {"и", "i"},
            {"й", "y"}, {"к", "k"}, {"л", "l"}, {"м", "m"}, {"н", "n"}, {"о", "o"}, {"п", "p"}, {"р", "r"},
            {"с", "s"}, {"т", "t"}, {"у", "u"}, {"ф", "f"}, {"ы", "i"}, {"э", "e"},
    };

    static String transliterate(String s) {
        boolean hasCyr = false;
        for (int i = 0; i < s.length(); i++) {
            char ch = s.charAt(i);
            if (ch >= 0x0400 && ch <= 0x04FF) {
                hasCyr = true;
                break;
            }
        }
        if (!hasCyr) return s;
        // Kirill so'zlar boshidagi "е" → "ye"
        s = s.replaceAll("(^|\\s)е", "$1ye");
        for (String[] p : CYR) s = s.replace(p[0], p[1]);
        return s;
    }
}
