#!/usr/bin/env python3
from pathlib import Path
import subprocess

ROOT = Path("/var/www/chillflix-newsite")
js_path = ROOT / "public/assets/js/app.js"
js = js_path.read_text()
needle = (
    "$('#language-toggler').attr('title', 'Language: ' + label);\n"
    "    syncBrowseSettingsUi();\n"
    "  }"
)
if "nsPushPrefs({ language" not in js and needle in js:
    js = js.replace(
        needle,
        "$('#language-toggler').attr('title', 'Language: ' + label);\n"
        "    syncBrowseSettingsUi();\n"
        "    try { nsPushPrefs({ language: lang }); } catch (eLang) {}\n"
        "  }",
        1,
    )
    js_path.write_text(js)
    print("language push added")
else:
    print("language push skip", "nsPushPrefs({ language" in js)

php = r'''<?php
$GLOBALS["__cf_config_override"] = ["turnstile_secret_key" => ""];
require "/var/www/chillflix-newsite/app/helpers.php";
require "/var/www/chillflix-newsite/app/Services/Database.php";
require "/var/www/chillflix-newsite/app/Services/Auth.php";
require "/var/www/chillflix-newsite/app/Services/UserData.php";
$email = "testuser_".time()."@example.com";
$user = Auth::register("Test User", $email, "testpass1");
echo "reg role={$user["role"]}\n";
UserData::upsertFavorite($user["id"], ["type"=>"movie","id"=>550,"title"=>"Fight Club","poster"=>"/x.jpg","year"=>"1999"]);
UserData::upsertContinue($user["id"], ["type"=>"movie","id"=>550,"title"=>"Fight Club","t"=>120,"d"=>8000]);
UserData::updatePrefs($user["id"], ["autoplayEnabled"=>true,"autoNextEnabled"=>false,"language"=>"de"]);
$lib = UserData::library($user["id"]);
echo "fav={$lib["favorites"][0]["title"]} cw_t={$lib["continueWatching"][0]["t"]} lang={$lib["user"]["language"]} auto=".($lib["user"]["autoplayEnabled"]?"1":"0")."\n";
Database::pdo()->prepare("UPDATE users SET role='moderator' WHERE id=?")->execute([$user["id"]]);
echo "moderator ok\n";
'''
Path("/tmp/ns_int_test.php").write_text(php)
print(subprocess.check_output(["php", "/tmp/ns_int_test.php"], text=True))
