-- The storefront's own data. Nothing here is the source of truth for a price,
-- stock or an order's state: those live in the ERP and are re-read from it.

CREATE TABLE sessions (
    id            VARCHAR(128) NOT NULL,
    data          MEDIUMBLOB   NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A visitor's basket. The token is the cookie; prices are never kept here.
CREATE TABLE carts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token      CHAR(40)        NOT NULL,
    created_at DATETIME        NOT NULL,
    updated_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY carts_token (token),
    KEY carts_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_items (
    cart_id       BIGINT UNSIGNED NOT NULL,
    sku           VARCHAR(64)     NOT NULL,
    product_id    VARCHAR(40)     NOT NULL,
    product_slug  VARCHAR(190)    NOT NULL,
    product_name  VARCHAR(255)    NOT NULL,
    variant_label VARCHAR(255)    NULL,
    image_url     VARCHAR(1024)   NULL,
    quantity      INT UNSIGNED    NOT NULL,
    created_at    DATETIME        NOT NULL,
    updated_at    DATETIME        NOT NULL,
    PRIMARY KEY (cart_id, sku),
    CONSTRAINT cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A checkout from details to placed order. `reference` is the unique
-- storefront_order_reference; `idempotency_key` changes whenever the customer
-- has to reconfirm after a price change (contract §6.1.1).
CREATE TABLE checkouts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token            CHAR(40)        NOT NULL,
    cart_id          BIGINT UNSIGNED NULL,
    reference        VARCHAR(64)     NULL,
    idempotency_key  CHAR(36)        NOT NULL,
    status           VARCHAR(16)     NOT NULL DEFAULT 'draft',
    customer_json    JSON            NOT NULL,
    shipping_json    JSON            NOT NULL,
    billing_json     JSON            NULL,
    items_json       JSON            NOT NULL,
    payment_method   VARCHAR(20)     NOT NULL,
    customer_note    VARCHAR(1000)   NULL,
    quote_json       JSON            NULL,
    placed_order_id  VARCHAR(40)     NULL,
    created_at       DATETIME        NOT NULL,
    updated_at       DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY checkouts_token (token),
    UNIQUE KEY checkouts_reference (reference),
    KEY checkouts_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What this storefront knows of an order, so a customer can find it again. The
-- ERP's answer is always re-read for anything that matters.
CREATE TABLE orders (
    id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id                  VARCHAR(40)     NOT NULL,
    reference                  VARCHAR(64)     NOT NULL,
    storefront_order_reference VARCHAR(64)     NOT NULL,
    access_token               CHAR(40)        NOT NULL,
    customer_name              VARCHAR(160)    NOT NULL,
    customer_mobile            VARCHAR(32)     NOT NULL,
    customer_email             VARCHAR(254)    NULL,
    status                     VARCHAR(64)     NOT NULL,
    payment_method             VARCHAR(20)     NOT NULL,
    grand_total                VARCHAR(40)     NOT NULL,
    currency                   CHAR(3)         NOT NULL,
    placed_at                  DATETIME        NOT NULL,
    updated_at                 DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY orders_public_id (public_id),
    UNIQUE KEY orders_storefront_reference (storefront_order_reference),
    KEY orders_reference (reference),
    KEY orders_mobile (customer_mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A read-through copy of catalogue and site reads, so a busy page does not spend
-- the ERP's rate limit. `tag` is what a webhook invalidates by.
CREATE TABLE api_cache (
    cache_key  VARCHAR(190) NOT NULL,
    tag        VARCHAR(64)  NOT NULL,
    payload    MEDIUMTEXT   NOT NULL,
    stored_at  DATETIME     NOT NULL,
    expires_at DATETIME     NOT NULL,
    PRIMARY KEY (cache_key),
    KEY api_cache_tag (tag),
    KEY api_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook deliveries are at-least-once: the delivery id is the dedupe key (§7.3).
CREATE TABLE webhook_deliveries (
    delivery_id VARCHAR(64) NOT NULL,
    event       VARCHAR(64) NOT NULL,
    occurred_at DATETIME    NULL,
    received_at DATETIME    NOT NULL,
    PRIMARY KEY (delivery_id),
    KEY webhook_deliveries_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window counters for the few endpoints that must not be hammered.
CREATE TABLE throttle (
    bucket       VARCHAR(190) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits         INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
