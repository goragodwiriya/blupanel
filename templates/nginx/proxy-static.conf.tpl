
    # A static file answers directly from nginx — no need to wake up one Apache process per image
    #
    # Split into two locations, because these two layers know different amounts:
    #
    #   1. A file at the website's root — the root's `.htaccess` was already
    #      checked, when this file was written, to have only rewrite rules
    #      (if it had an access-control rule, this whole block would never
    #      have been written out at all) — so it can answer immediately with
    #      no further checking — this is the path WordPress/Laravel take,
    #      whose front-controller .htaccess always sits at the root
    #   2. A file in a subfolder — **checks every request** for whether that
    #      folder has a `.htaccess` · a customer can upload one via SFTP at
    #      any time with the panel having no idea · trusting only a past scan
    #      would leave a folder the customer just protected still open to
    #      everyone until the next vhost write, which might not happen again for months
    #
    # `return` inside `if` is one of the two forms nginx certifies as safe
    # (the other is rewrite) · 418 is a code nobody genuinely uses, so it's usable as an internal signal to forward on to @backend

    location ~* ^/[^/]+\.(?:css|js|mjs|jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|woff|woff2|ttf|otf|eot|mp4|webm|ogg|mp3|wav|pdf|zip|gz|txt|map)$ {
        root {{DOCROOT}};

        try_files $uri @backend;

        access_log off;
        expires 7d;
        add_header Cache-Control "public";
    }

    location ~* ^(?<phpcp_dir>/.+/)[^/]+\.(?:css|js|mjs|jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|woff|woff2|ttf|otf|eot|mp4|webm|ogg|mp3|wav|pdf|zip|gz|txt|map)$ {
        root {{DOCROOT}};

        error_page 418 = @backend;

        # A .htaccess in the same folder as the requested file = it might
        # have a rule nginx can't handle on its own — let Apache answer it directly, no need to guess what's written inside
        if (-f "$document_root$phpcp_dir.htaccess") {
            return 418;
        }

        try_files $uri @backend;

        access_log off;
        expires 7d;
        add_header Cache-Control "public";
    }

    # A hidden file must never slip through this path — the same rule Apache enforces
    #
    # **Excludes `.well-known`, and it genuinely has to be excluded** — Let's
    # Encrypt proves ownership under HTTP-01 by downloading
    # `/.well-known/acme-challenge/<token>`, which starts with a dot exactly
    # · a regex location is always considered before `location /`, so this
    # rule would answer 403 right at nginx, with Apache and certbot never
    # seeing that request at all — the result is **the certificate request
    # fails forever**, and the message certbot returns talks about a failed
    # challenge without pointing at nginx even once
    #
    # `(?!well-known/)` is a PCRE negative lookahead, which nginx supports —
    # excludes only this one specific path, every other hidden file is still refused exactly as before
    location ~ /\.(?!well-known/) {
        return 403;
    }
