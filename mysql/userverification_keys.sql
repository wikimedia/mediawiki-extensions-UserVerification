CREATE TABLE IF NOT EXISTS /*_*/userverification_keys (
  `id` enum('1') NOT NULL,
  `public_key` BLOB NOT NULL,
  `protected_key` BLOB NOT NULL,
  `encrypted_private_key` BLOB NOT NULL,
  `enabled` TINYINT(1) NOT NULL default 1,
  `updated_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE /*_*/userverification_keys
  ADD PRIMARY KEY (`id`);

