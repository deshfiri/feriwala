-- The gateway a customer chose for an online payment (one the ERP offered).
ALTER TABLE checkouts ADD COLUMN gateway VARCHAR(40) NULL AFTER payment_method;
