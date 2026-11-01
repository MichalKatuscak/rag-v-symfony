#!/bin/sh
# Převede český Hunspell slovník (balík hunspell-cs) do tsearch_data PostgreSQL.
# iconv -c zahodí neplatné bajty UTF-8 (v komentářích .aff jsou rozbité znaky, na kterých PostgreSQL padá).
set -e
TS="$(pg_config --sharedir)/tsearch_data"
iconv -f UTF-8 -t UTF-8 -c /usr/share/hunspell/cs_CZ.aff > "$TS/czech.affix"
iconv -f UTF-8 -t UTF-8 -c /usr/share/hunspell/cs_CZ.dic > "$TS/czech.dict"
