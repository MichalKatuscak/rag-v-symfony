FROM pgvector/pgvector:pg18

# Český slovník Hunspell (balík hunspell-cs) převedený do formátu, který čte šablona ispell v PostgreSQL.
RUN apt-get update \
 && apt-get install -y --no-install-recommends hunspell-cs \
 && rm -rf /var/lib/apt/lists/*

COPY czech.stop /usr/share/postgresql/18/tsearch_data/czech.stop
COPY prepare-dictionary.sh /usr/local/bin/prepare-dictionary.sh
RUN sh /usr/local/bin/prepare-dictionary.sh

COPY init.sql /docker-entrypoint-initdb.d/10-init.sql
