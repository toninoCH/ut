# ffmpeg 6.0/ffprobe nach ~/bin (statisch, SHA256 fest geprüft, Probe-Umwandlung) | EXPECTED=1-2 Min | TARGET=SERVER | MODE=WRITE (nur ~/bin, Shop unberührt)
gff() {
  local G=/home/u928734560/rollback
  local URL=https://github.com/eugeneware/ffmpeg-static/releases/download/b6.0
  local SHA_FFMPEG=17c1ae10b52ac499180679fe6ba77e17642390c4eedb0f1e3b0ac045da55128f SHA_FFPROBE=88ca6818d82a70e87de2bb6166026ad411528c72eda0e4e11ed25d185116ee17
  local TS D REP RB TMP BIN NEWBIN=nein ST=SUCCESS ERR=NONE VAL=FAIL HC=NOT_RUN VER="" ENC=nein PHPT=nein DUR="" RES b
  TS=$(date +%Y%m%d_%H%M%S); D=$HOME/geoconvado_check; REP=$D/ffmpeg_install_$TS.txt; RB=$G/ffmpeg_$TS; TMP=$D/.ff_$TS; BIN=$HOME/bin
  mkdir -p "$D" "$RB" "$TMP" || { echo "STATUS=FAILED ERROR=mkdir"; return 0; }
  : > "$REP"
  [ "$(uname -m)" = x86_64 ] || ERR=arch_nicht_x86_64
  for c in curl php; do [ "$ERR" = NONE ] && ! command -v "$c" >/dev/null 2>&1 && ERR=fehlt_$c; done
  # --- Rollback vorbereiten: vorhandene Dateien sichern, Rückbau-Skript ablegen
  [ -d "$BIN" ] || NEWBIN=ja
  for f in ffmpeg ffprobe; do [ -e "$BIN/$f" ] && cp -p "$BIN/$f" "$RB/$f.bak"; done
  printf 'for f in ffmpeg ffprobe; do if [ -e "%s/$f.bak" ]; then cp -p "%s/$f.bak" "%s/$f"; else rm -f "%s/$f"; fi; done\n[ "%s" = ja ] && rmdir "%s" 2>/dev/null\necho ROLLBACK_DONE\n' "$RB" "$RB" "$BIN" "$BIN" "$NEWBIN" "$BIN" > "$RB/rollback.sh"
  # --- Download, SHA256 (fest hinterlegt), Entpacken mit PHP (zlib) – braucht weder xz noch Python
  local UNP=php_zlib f h
  if [ "$ERR" = NONE ]; then
    for f in ffmpeg ffprobe; do
      [ "$ERR" = NONE ] || continue
      curl -s -f -L -m 180 -o "$TMP/$f.gz" "$URL/$f-linux-x64.gz" || { ERR=download_$f; continue; }
      h=$(php -r 'echo hash_file("sha256",$argv[1]);' "$TMP/$f.gz")
      [ "$f" = ffmpeg ] && [ "$h" != "$SHA_FFMPEG" ] && ERR=pruefsumme_$f
      [ "$f" = ffprobe ] && [ "$h" != "$SHA_FFPROBE" ] && ERR=pruefsumme_$f
      [ "$ERR" = NONE ] && { php -r 'exit(copy("compress.zlib://".$argv[1],$argv[2])?0:1);' "$TMP/$f.gz" "$TMP/$f" || ERR=entpacken_$f; }
    done
  fi
  if [ "$ERR" = NONE ]; then
    mkdir -p "$BIN" && for f in ffmpeg ffprobe; do cp "$TMP/$f" "$BIN/$f" && chmod 755 "$BIN/$f" || ERR=kopieren; done
  fi
  # --- Validierung: Version, Encoder, 3-Sek.-Testvideo H.264/AAC, Start aus PHP (proc_open)
  if [ "$ERR" = NONE ]; then
    VER=$("$BIN/ffmpeg" -hide_banner -version 2>/dev/null | head -n 1 | cut -d' ' -f3)
    "$BIN/ffmpeg" -hide_banner -encoders > "$TMP/enc" 2>/dev/null; grep -q ' libx264 ' "$TMP/enc" && grep -q ' aac ' "$TMP/enc" && ENC=ja
    timeout 60 "$BIN/ffmpeg" -nostdin -hide_banner -loglevel error -y -f lavfi -i testsrc=duration=3:size=1280x720:rate=25 -f lavfi -i sine=duration=3 -c:v libx264 -preset veryfast -crf 26 -pix_fmt yuv420p -c:a aac -shortest -movflags +faststart "$TMP/t.mp4" 2>>"$REP"
    DUR=$(timeout 20 "$BIN/ffprobe" -v error -show_entries format=duration -of csv=p=0 "$TMP/t.mp4" 2>/dev/null | cut -c1-4)
    PHPT=$(php -r '$p=@proc_open([$argv[1],"-hide_banner","-version"],[1=>["pipe","w"],2=>["pipe","w"]],$io);if(!is_resource($p)){echo "nein";return;}stream_get_contents($io[1]);fclose($io[1]);fclose($io[2]);echo proc_close($p)===0?"ja":"nein";' "$BIN/ffmpeg" 2>/dev/null)
    [ -n "$VER" ] && [ "$ENC" = ja ] && [ -n "$DUR" ] && [ "$PHPT" = ja ] && VAL=PASS || ERR=validierung
  fi
  if [ "$ERR" != NONE ]; then bash "$RB/rollback.sh" >> "$REP" 2>&1; ST=ROLLED_BACK; [ "$ERR" = arch_nicht_x86_64 ] && ST=BLOCKED; fi
  if [ -r "$G/bin/healthcheck.sh" ]; then { echo "== HEALTHCHECK"; timeout 120 bash "$G/bin/healthcheck.sh"; } >> "$REP" 2>&1 && HC=PASS || HC=FAIL; fi
  rm -rf "$TMP"
  { echo "== FFMPEG status=$ST error=$ERR entpacker=$UNP version=$VER encoder=$ENC testvideo_sek=$DUR php_start=$PHPT bin=$BIN neu_angelegt=$NEWBIN"; ls -la "$BIN" 2>/dev/null; echo "rollback: bash $RB/rollback.sh"; } >> "$REP"
  # --- Governance: Einträge passend zu den vorhandenen Spaltenköpfen
  gl() { local f=$1 h line="" c v; [ -f "$f" ] || return 0; h=$(head -n 1 "$f")
    for c in $(printf '%s' "$h" | tr '\t' '\n' | tr ' ' '_'); do
      case "$(printf '%s' "$c" | tr 'A-Z' 'a-z')" in
        *date*|*datum*|*time*|*zeit*|ts) v=$(date '+%Y-%m-%d %H:%M:%S');; *target*|*env*|*umgebung*|*ziel*) v=SERVER;; *status*) v=$ST;;
        *rollback*|*backup*|*pfad*|*path*) v=$RB;; *task*|*id*) v=ffmpeg_$TS;; *health*) v=$HC;; *report*) v=$REP;;
        *change*|*aender*|*änder*|*desc*|*beschreib*|*was*|*summary*|*note*|*notiz*) v="ffmpeg $VER statisch (johnvansickle via GitHub, SHA256) nach ~/bin (GEO.Convado Video), Validierung $VAL";;
        *user*|*wer*|*by*|*autor*) v=claude;; *) v="";; esac
      line="$line$v	"; done
    printf '%s\n' "${line%	}" >> "$f"; }
  gl "$G/ROLLBACK_INDEX.tsv"; gl "$G/CHANGELOG.tsv"
  RES="TASK=ffmpeg_install
STATUS=$ST
TARGET=SERVER
CHANGED=$( [ "$ST" = SUCCESS ] && echo "ffmpeg+ffprobe $VER nach $BIN" || echo NONE)
VALIDATION=$VAL
HEALTHCHECK=$HC
ERROR=$ERR
ROLLBACK=$RB/rollback.sh
REPORT=$REP
LIVE=UNTOUCHED
ENTPACKER=$UNP FFMPEG=$BIN/ffmpeg FFPROBE=$BIN/ffprobe H264_AAC=$ENC TESTVIDEO_SEK=$DUR PHP_START=$PHPT"
  b=$(printf '%s' "$RES" | wc -c)
  if [ "$b" -lt 2048 ] && ! printf '%s' "$RES" | grep -q -E '\$\(|#!/|<\?php|SELECT |gff\(|password'; then
    printf '\033]52;c;%s\a' "$(printf '%s' "$RES" | base64 | tr -d '\n')"; echo "$RES"; echo "OSC52=RESULT_COPIED"; echo "OSC52_BYTES=$b"
  else echo "OSC52=BLOCKED"; echo "REPORT=$REP"; fi
  return 0
}
gff
