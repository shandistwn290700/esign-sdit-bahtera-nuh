// Create signing-service/.env.dev with random development secrets (never used in production).
// Usage: node scripts/dev-env.mjs
import { randomBytes } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";

const target = new URL("../signing-service/.env.dev", import.meta.url);
if (existsSync(target)) {
  console.log("signing-service/.env.dev sudah ada, tidak diubah.");
  process.exit(0);
}
const values = {
  SIGNER_API_TOKENS: randomBytes(32).toString("hex"),
  SIGNER_HSM_PIN: randomBytes(12).toString("hex"),
  SIGNER_HSM_SO_PIN: randomBytes(12).toString("hex"),
  SIGNER_TOTP_ENCRYPTION_KEY: randomBytes(32).toString("base64"),
};
const template = readFileSync(new URL("../signing-service/.env.dev.example", import.meta.url), "utf8");
const filled = template.replace(/^(\w+)=$/gm, (line, key) => (key in values ? `${key}=${values[key]}` : line));
writeFileSync(target, filled, { mode: 0o600 });
console.log("signing-service/.env.dev dibuat.");
