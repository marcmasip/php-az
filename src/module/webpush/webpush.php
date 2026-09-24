<?php
/**
 * php-az-webpush - webpush.php
 * minimal, low-ceremony, macro-driven:
 * webpush implementation with only openssl
 * 
 * @author Marc Masip Marín + / marc at azestudio.net
 */
namespace webpush;

class util {
    
    static function b64url_enc($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    static function b64url_dec($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    // Convierte llave pública RAW (0x04...) de 65 bytes a PEM para OpenSSL
    static function rawpem($raw) {
        // Prefijo ASN.1 estándar para prime256v1 (secp256r1)
        $der = hex2bin("3059301306072a8648ce3d020106082a8648ce3d030107034200") . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    // Convierte PEM a RAW extrayendo los últimos 65 bytes
    static function pemraw($pem) {
        $der = base64_decode(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"], '', $pem));
        return substr($der, -65);
    }
}

class vapid {

    // Genera el JWT y extrae la firma ECDSA de ASN.1 DER a Raw R+S (El gran truco 0-deps)
    static function jwt($endpoint, $admin_email, $priv_pem) {
        $origin = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        
        $header = util::b64url_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = util::b64url_enc(json_encode([
            'aud' => $origin,
            'exp' => time() + 43200,
            'sub' => "mailto:$admin_email"
        ]));

        $unsigned = "$header.$payload";
        openssl_sign($unsigned, $der, $priv_pem, OPENSSL_ALGO_SHA256);

        // Parseador minimalista de ASN.1 DER para sacar R y S (32 bytes cada uno)
        $r_len = ord($der[3]);
        $r = ltrim(substr($der, 4, $r_len), "\x00"); // Quitamos padding 0x00 si el MSB era 1
        
        $s_len = ord($der[4 + $r_len + 1]);
        $s = ltrim(substr($der, 4 + $r_len + 2, $s_len), "\x00");

        $raw_sig = str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
        
        return $unsigned . '.' . util::b64url_enc($raw_sig);
    }
}

class payload {
    static function encrypt($payload_text, $client_pub_b64, $client_auth_b64) {
        $client_pub = util::b64url_dec($client_pub_b64);
        $client_auth = util::b64url_dec($client_auth_b64);
        $salt = random_bytes(16);

        // 1. Generamos clave efímera
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($res, $eph_priv_pem);
        $eph_pub = util::pemraw(openssl_pkey_get_details($res)['key']);

        // 2. ECDH - Secreto compartido
        $shared_secret = openssl_pkey_derive(util::rawpem($client_pub), $eph_priv_pem);

        // 3. HKDF (RFC 8291)
        $info = "WebPush: info\x00" . $client_pub . $eph_pub;
        $ikm = hash_hkdf('sha256', $shared_secret, 32, $info, $client_auth);
        
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // 4. Encriptar AES-128-GCM (Payload + 0x02 de padding delimiter según RFC 8188)
        $record = $payload_text . chr(2); 
        $ciphertext = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        // 5. Ensamblaje binario (Header + Payload)
        return $salt . pack('N', 4096) . chr(65) . $eph_pub . $ciphertext . $tag;
    }
}

class push {
    
    static function send( $sub_json, $payload=[
            'title' => '¡Hola Mundo!',
            'body' => 'Esto es un push 0 dependencias',
            'icon' => '/icono.png'
        ]) {
        
        $payload_text = json_encode();

        $vapid_pub_b64 = \conf("webpush_pub");
        $vapid_priv_pem = \conf("webpush_pk");
        $admin_email = \conf("webpush_admin");

        $sub = is_string($sub_json) ? json_decode($sub_json, true) : $sub_json;
        $endpoint = $sub['endpoint'];

        $jwt = vapid::jwt($endpoint, $admin_email, $vapid_priv_pem);
        $body = payload::encrypt($payload_text, $sub['keys']['p256dh'], $sub['keys']['auth']);

        $headers = [
            "Authorization: vapid t=$jwt, k=$vapid_pub_b64",
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400'
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); 
        
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => ($httpcode >= 200 && $httpcode < 300),
            'expired' => ($httpcode === 404 || $httpcode === 410),
            'code' => $httpcode
        ];
    }
}