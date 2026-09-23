<?php
namespace webpush;

$res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
openssl_pkey_export($res, $priv_pem);
$pub_raw = util::pemraw(openssl_pkey_get_details($res)['key']);

echo "Key 1: ------\n";
echo util::b64url_enc($pub_raw)."\n";
echo "Fin Key 1-----\n";
echo "Key 2: ------\n";
echo $priv_pem."\n";
echo "Fin Key 2: ------\n";
