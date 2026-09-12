-- Crédits/facturation (cahier des charges V2.0 §34, Phase 2) : le cahier des
-- charges d'origine demande explicitement de "préparer une architecture
-- compatible avec un futur système de recharge/paiement", pas d'intégrer un
-- vrai moyen de paiement — aucune passerelle de paiement n'existe dans ce
-- projet, la recharge reste manuelle (par un SUPER_ADMIN) jusqu'à ce qu'une
-- vraie intégration soit décidée.
--
-- Ce solde de crédits est INTERNE à la plateforme, distinct du solde Orange
-- réel (`orangeSms()->getBalance()`) : plusieurs organisations partagent un
-- même compte Orange (§59), donc sans cette comptabilité séparée par
-- organisation, rien n'empêcherait une organisation d'épuiser le solde
-- Orange partagé au détriment des autres. Le crédit par défaut (100000) est
-- volontairement généreux pour ne bloquer aucune organisation existante au
-- moment de cette migration — à ajuster ensuite par organisation depuis la
-- page Crédits.

ALTER TABLE organizations ADD COLUMN IF NOT EXISTS credits_balance INTEGER NOT NULL DEFAULT 100000;

CREATE TABLE IF NOT EXISTS credit_transactions (
    id SERIAL PRIMARY KEY,
    organization_id INTEGER NOT NULL REFERENCES organizations(id),
    type VARCHAR(10) NOT NULL, -- 'credit' (recharge) | 'debit' (consommation SMS)
    amount INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    description TEXT,
    campagne_id INTEGER REFERENCES campagne(id) ON DELETE SET NULL,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_credit_transactions_organization ON credit_transactions (organization_id, created_at DESC);
