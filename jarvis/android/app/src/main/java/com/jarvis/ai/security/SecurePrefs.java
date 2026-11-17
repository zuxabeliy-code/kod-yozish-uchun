package com.jarvis.ai.security;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import android.util.Log;

import java.nio.ByteBuffer;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/**
 * Maxfiy qiymatlarni (qurilma tokeni) Android Keystore'dagi AES-256-GCM kalit bilan shifrlab saqlaydi.
 * Kalit apparat xavfsiz hududida turadi va ilovadan tashqariga chiqmaydi.
 * Tashqi kutubxona (androidx.security) talab qilinmaydi.
 */
public final class SecurePrefs {

    private static final String TAG = "JarvisSecurePrefs";
    private static final String KEYSTORE = "AndroidKeyStore";
    private static final String KEY_ALIAS = "jarvis_master_key";
    private static final String PREFS = "jarvis_secure";
    private static final int IV_LEN = 12;

    private final SharedPreferences prefs;

    public SecurePrefs(Context ctx) {
        prefs = ctx.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public void putString(String key, String value) {
        if (value == null || value.isEmpty()) {
            prefs.edit().remove(key).apply();
            return;
        }
        try {
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
            byte[] iv = cipher.getIV();
            byte[] enc = cipher.doFinal(value.getBytes(StandardCharsets.UTF_8));
            ByteBuffer buf = ByteBuffer.allocate(iv.length + enc.length);
            buf.put(iv).put(enc);
            prefs.edit().putString(key, Base64.encodeToString(buf.array(), Base64.NO_WRAP)).apply();
        } catch (Exception e) {
            Log.e(TAG, "Shifrlash xatosi", e);
            throw new IllegalStateException("Tokenni xavfsiz saqlab bo'lmadi", e);
        }
    }

    /** Topilmasa yoki shifr ochilmasa — null. */
    public String getString(String key) {
        String stored = prefs.getString(key, null);
        if (stored == null) return null;
        try {
            byte[] all = Base64.decode(stored, Base64.NO_WRAP);
            if (all.length <= IV_LEN) return null;
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), new GCMParameterSpec(128, all, 0, IV_LEN));
            byte[] dec = cipher.doFinal(all, IV_LEN, all.length - IV_LEN);
            return new String(dec, StandardCharsets.UTF_8);
        } catch (Exception e) {
            // Masalan, ilova ma'lumotlari tiklangan, lekin Keystore kaliti yo'q.
            Log.w(TAG, "Shifr ochilmadi, qiymat o'chiriladi", e);
            prefs.edit().remove(key).apply();
            return null;
        }
    }

    public boolean has(String key) {
        return prefs.contains(key);
    }

    private static SecretKey getOrCreateKey() throws Exception {
        KeyStore ks = KeyStore.getInstance(KEYSTORE);
        ks.load(null);
        KeyStore.Entry entry = ks.getEntry(KEY_ALIAS, null);
        if (entry instanceof KeyStore.SecretKeyEntry) {
            return ((KeyStore.SecretKeyEntry) entry).getSecretKey();
        }
        KeyGenerator kg = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE);
        kg.init(new KeyGenParameterSpec.Builder(KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build());
        return kg.generateKey();
    }
}
