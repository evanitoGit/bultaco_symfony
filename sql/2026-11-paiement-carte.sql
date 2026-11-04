-- Paiement par carte bancaire (Stripe Checkout)
ALTER TABLE `commandes`
  MODIFY `mode_paiement` enum('virement','cheque','carte') NOT NULL,
  ADD COLUMN `stripe_session_id` varchar(255) NULL DEFAULT NULL COMMENT 'session de paiement Stripe en cours',
  ADD COLUMN `stripe_paiement_id` varchar(255) NULL DEFAULT NULL COMMENT 'paiement Stripe (pi_…), pour retrouver ou rembourser',
  ADD COLUMN `date_paiement` datetime NULL DEFAULT NULL,
  ADD KEY `stripe_session_id` (`stripe_session_id`);
