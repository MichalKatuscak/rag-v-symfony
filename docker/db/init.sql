-- Rozšíření a konfigurace fulltextu pro češtinu.
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;

-- Česká stop slova (soubor czech.stop přidává Dockerfile).
-- Varianta 1: „simple“ bez diakritiky. Žádné tvary slov, jen malá písmena a odstranění háčků a čárek.
CREATE TEXT SEARCH DICTIONARY cs_stop (TEMPLATE = simple, STOPWORDS = czech);
CREATE TEXT SEARCH CONFIGURATION cs_simple (COPY = simple);
ALTER TEXT SEARCH CONFIGURATION cs_simple
    ALTER MAPPING FOR asciiword, asciihword, hword_asciipart, word, hword, hword_part
    WITH unaccent, cs_stop;

-- Varianta 2: český Hunspell (základní tvar slova), slova mimo slovník projdou jako v cs_simple.
CREATE TEXT SEARCH DICTIONARY cs_hunspell (TEMPLATE = ispell, DictFile = czech, AffFile = czech, StopWords = czech);
CREATE TEXT SEARCH CONFIGURATION cs_lemma (COPY = simple);
ALTER TEXT SEARCH CONFIGURATION cs_lemma
    ALTER MAPPING FOR asciiword, asciihword, hword_asciipart, word, hword, hword_part
    WITH cs_hunspell, cs_stop;

-- Základní tvary bez diakritiky: nejdřív Hunspell (potřebuje háčky), pak unaccent na výsledné lexémy.
-- Pozice slov se tím ztratí, proto se řadí přes ts_rank, ne ts_rank_cd.
CREATE FUNCTION cs_lemma_vector(t text) RETURNS tsvector
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN array_to_tsvector(ARRAY(
        SELECT DISTINCT public.unaccent('public.unaccent', lexeme)
        FROM unnest(to_tsvector('public.cs_lemma', t)) AS u
    ));

CREATE FUNCTION cs_lemma_query(t text) RETURNS tsquery
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN (SELECT CASE WHEN count(*) = 0 THEN ''::tsquery
                        ELSE to_tsquery('simple', string_agg(quote_literal(l), ' | ')) END
            FROM (SELECT DISTINCT public.unaccent('public.unaccent', lexeme) AS l
                  FROM unnest(to_tsvector('public.cs_lemma', t)) AS u) s);

-- Dotaz pro cs_simple: slova spojená NEBO (A by vyžadovalo všechna slova otázky).
CREATE FUNCTION cs_simple_query(t text) RETURNS tsquery
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN (SELECT CASE WHEN count(*) = 0 THEN ''::tsquery
                        ELSE to_tsquery('simple', string_agg(quote_literal(lexeme), ' | ')) END
            FROM unnest(to_tsvector('public.cs_simple', t)) AS u);
