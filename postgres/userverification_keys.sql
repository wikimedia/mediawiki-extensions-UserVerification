CREATE TABLE IF NOT EXISTS userverification_keys (
  `id` enum('1') NOT NULL,
  `public_key` BYTEA NOT NULL,
  `protected_key` BYTEA NOT NULL,
  `encrypted_private_key` BYTEA NOT NULL,
  `enabled` TINYINT(1) NOT NULL default 1,
  `updated_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE userverification_keys
  ADD PRIMARY KEY (`id`);

