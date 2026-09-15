#!/bin/bash
# Baut die lokale OrderSprinter-3.0.8-Testinstanz komplett neu auf.
# MariaDB laeuft in podman auf Port 13306, der Webserver ist PHPs eingebauter Server auf 8765.
#
# ACHTUNG: Saemtliche Passwoerter in dieser Datei sind Wegwerfwerte fuer eine
# rein lokale Testinstanz, die nur auf 127.0.0.1 hoert und nie aus dem Netz
# erreichbar ist. Sie haben nichts mit der Installation des Vereins zu tun und
# duerfen dort NICHT verwendet werden.
set -e
P=/var/lib/freelancer/projects/40708545
BASE=http://127.0.0.1:8765

echo "== 1. Datenbank =="
podman rm -f os-db >/dev/null 2>&1 || true
podman run -d --name os-db \
  -e MARIADB_ROOT_PASSWORD=ospass -e MARIADB_DATABASE=ordersprinter \
  -e MARIADB_USER=osuser -e MARIADB_PASSWORD=ospass \
  -p 13306:3306 docker.io/library/mariadb:11 >/dev/null
for i in $(seq 1 30); do
  mysql -h 127.0.0.1 -P 13306 -uosuser -pospass -e "select 1" >/dev/null 2>&1 && break
  sleep 2
done
echo "   DB bereit"

echo "== 2. Applikation =="
rm -rf "$P/app"
cp -r "$P/dl/src/coresystem" "$P/app"
pkill -f "php -S 127.0.0.1:8765" >/dev/null 2>&1 || true
sleep 1
(cd "$P/app" && nohup setsid php -S 127.0.0.1:8765 -t . > "$P/php-server.log" 2>&1 < /dev/null &)
sleep 3

echo "== 3. Installer =="
curl -sS -X POST "$BASE/install/installer.php?command=install" \
 -d host=127.0.0.1 -d port=13306 -d db=ordersprinter -d user=osuser -d password=ospass -d prefix=os_ \
 -d timezone=Europe/Berlin -d point=, -d lang=0 -d currency=Euro \
 -d "dsfinvk_name=SG Huettenfeld" -d "dsfinvk_street=Musterweg 1" -d dsfinvk_postalcode=69517 \
 -d "dsfinvk_city=Huettenfeld" -d dsfinvk_country=Deutschland -d dsfinvk_stnr="" -d dsfinvk_ustid="" \
 -d cat1name=Speisen -d cat2name=Getränke -d prodlistname=Speisekarte \
 -d cat1viewname=Speisenausgabe -d cat2viewname=Getränkeausgabe -d deskviewname=Kellneransicht \
 -d paydeskid=1 -d restaurantmode=1 -d cancelcode=123 -d printpass=123 -d defaultview=0 \
 -d adminpass=admin123 | tail -c 60
echo

echo "== 4. Login + Speisekarte =="
rm -f "$P/cj.txt"
curl -sS -c "$P/cj.txt" -X POST "$BASE/php/contenthandler.php?module=admin&command=tryAuthenticate" \
  -d userid=1 -d password=admin123 -d modus=1 -d time=$(date +%s) | head -c 80
echo
curl -sS -b "$P/cj.txt" -X POST "$BASE/php/contenthandler.php?module=admin&command=fillSpeisekarte" \
  --data-urlencode "speisekarte@$P/speisekarte-kerwe.txt" | head -c 80
echo

echo "== 5. Rollen =="
PERMS="is_admin right_waiter right_kitchen right_bar right_supply right_paydesk right_statistics right_bill right_products right_manager right_closing right_dash right_timetracking right_tasks right_tasksmanagement right_timemanager right_reservation right_rating right_changeprice right_customers right_pickups right_cashop right_delivery right_customersview right_payallorders"
mkrole() {
  name="$1"; shift; on=" $* "; args=""
  for p in $PERMS; do v=0; case "$on" in *" $p "*) v=1;; esac; args="$args -d $p=$v"; done
  curl -sS -b "$P/cj.txt" -X POST "$BASE/php/contenthandler.php?module=admin&command=createNewRole" \
    --data-urlencode "name=$name" $args >/dev/null
  echo "   Rolle $name"
}
mkrole "Kasse"            right_waiter right_paydesk right_bill right_changeprice right_pickups right_cashop right_closing right_statistics
mkrole "Bedienung"        right_waiter right_paydesk right_bill
mkrole "Speisenausgabe"   right_kitchen right_supply
mkrole "Getraenkeausgabe" right_bar right_supply

echo "== 6. Benutzer =="
mkuser() {
  curl -sS -b "$P/cj.txt" -X POST "$BASE/php/contenthandler.php?module=admin&command=createNewUser" \
    --data-urlencode "name=$1" --data-urlencode "fullname=$2" -d roleid=$3 \
    -d password=1234 -d tiptax=0 -d isowner=0 -d area=0 >/dev/null
  echo "   Benutzer $1"
}
mkuser "Kasse"            "Kasse Festplatz"     2
mkuser "Janine"           "Janine (Bedienung)"  3
mkuser "Sabine"           "Sabine (Bedienung)"  3
mkuser "Speisenausgabe"   "Speisenausgabe"      4
mkuser "Getraenkeausgabe" "Getraenkeausgabe"    5

echo "== 7. Raum Festzelt + Tische =="
python3 - <<'PY'
import json
tables=[{"id":"new%d"%i,"tablename":"T%d"%i,"name":"Tisch %d"%i,"area":0,
         "sorting":i,"active":1,"allowoutorder":0} for i in range(1,13)]
rooms=[{"roomid":"new1","name":"Festzelt","abbreviation":"FZ","printer":0,"sorting":1,"tables":tables}]
open("/var/lib/freelancer/projects/40708545/rooms.json","w").write(json.dumps(rooms))
PY
curl -sS -b "$P/cj.txt" -X POST "$BASE/php/contenthandler.php?module=roomtables&command=setRoomInfo" \
  --data-urlencode "rooms@$P/rooms.json" -d togoworkprinter=3 >/dev/null
echo "   Raum angelegt"

echo "== 8. Konfiguration =="
mysql -h 127.0.0.1 -P 13306 -uosuser -pospass ordersprinter <<'SQL'
UPDATE os_config SET setting='1' WHERE name='oneprodworkrecf';
UPDATE os_config SET setting='1' WHERE name='oneprodworkrecd';
UPDATE os_config SET setting='3' WHERE name='togoworkprinter';
UPDATE os_config SET setting='100' WHERE name='discount1';
UPDATE os_config SET setting='Frei'  WHERE name='discountname1';
INSERT INTO os_config (name,setting)
  SELECT 'singlebonusers','2' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM os_config WHERE name='singlebonusers');
SQL
echo "   Konfiguration gesetzt"

echo
echo "Fertig. $BASE  (admin/admin123, Kasse/1234, Janine/1234)"
