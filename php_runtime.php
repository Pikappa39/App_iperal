<?php

// Alcuni ambienti cloud bloccano la memoria eseguibile richiesta da PCRE JIT.
// Senza questa impostazione preg_match() puo fallire con errore 500.
ini_set('pcre.jit', '0');

