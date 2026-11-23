from __future__ import annotations

import json
from uuid import uuid4

from conftest import TOKEN, Env, make_pdf, sha

from signer.totp import MAX_FAILURES


def _code(resp: object) -> str:
    body = resp.json()  # type: ignore[attr-defined]
    code: str = body["code"]
    return code


class TestAuth:
    def test_missing_token(self, env: Env) -> None:
        resp = env.client.get("/v1/pki/chain")
        assert resp.status_code == 401
        assert resp.headers["content-type"] == "application/problem+json"
        assert _code(resp) == "unauthorized"

    def test_wrong_token(self, env: Env) -> None:
        resp = env.client.get("/v1/pki/chain", headers={"Authorization": "Bearer " + "x" * 40})
        assert resp.status_code == 401

    def test_health_is_public(self, env: Env) -> None:
        resp = env.client.get("/health")
        assert resp.status_code == 200
        assert resp.json()["status"] == "ok"

    def test_chain_and_crl(self, env: Env) -> None:
        chain = env.client.get("/v1/pki/chain", headers=env.auth)
        assert chain.text.count("BEGIN CERTIFICATE") == 2
        crl = env.client.get("/v1/pki/crl", headers=env.auth)
        assert crl.headers["content-type"] == "application/pkix-crl"
        assert "x-crl-next-update" in crl.headers


class TestSigners:
    def test_duplicate_ref_rejected(self, env: Env) -> None:
        ref = str(uuid4())
        body = {"signer_ref": ref, "full_name": "Ani", "position": "Guru", "email": "a@b.id"}
        assert env.client.post("/v1/signers", headers=env.auth, json=body).status_code == 201
        resp = env.client.post("/v1/signers", headers=env.auth, json=body)
        assert resp.status_code == 409 and _code(resp) == "signer_exists"

    def test_unknown_signer(self, env: Env) -> None:
        resp = env.client.get("/v1/signers/sk_01JDB8Q3V6X2N4M7P9R0S1T2V3", headers=env.auth)
        assert resp.status_code == 404 and _code(resp) == "signer_not_found"

    def test_validation_error_is_400(self, env: Env) -> None:
        resp = env.client.post("/v1/signers", headers=env.auth, json={"full_name": "x"})
        assert resp.status_code == 400 and _code(resp) == "validation_failed"

    def test_totp_lockout(self, env: Env) -> None:
        key_id, secret = env.new_signer()
        bad = str((int(env.code(secret)) + 500_000) % 1_000_000).zfill(6)
        reset = env.client.post(
            f"/v1/signers/{key_id}/totp/reset",
            headers=env.auth,
            json={"actor_ref": str(uuid4()), "reason": "test lockout"},
        )
        assert reset.status_code == 200 and reset.json()["status"] == "pending"
        for _ in range(MAX_FAILURES):
            resp = env.client.post(
                f"/v1/signers/{key_id}/totp/confirm", headers=env.auth, json={"totp_code": bad}
            )
            assert resp.status_code == 422
        resp = env.client.post(
            f"/v1/signers/{key_id}/totp/confirm", headers=env.auth, json={"totp_code": bad}
        )
        assert resp.status_code == 429 and _code(resp) == "totp_locked"
        assert int(resp.headers["retry-after"]) > 0


class TestDocuments:
    def test_full_sequential_flow(self, env: Env) -> None:
        wakasek, wakasek_secret = env.new_signer("Siti Aminah")
        kepsek, kepsek_secret = env.new_signer("Budi Santoso")
        prepared = env.prepare(make_pdf(), signers=2)

        # Second signer cannot go first.
        early = env.sign(prepared, "sig_2", kepsek, env.code(kepsek_secret))
        assert early.status_code == 409 and _code(early) == "previous_field_unsigned"

        first = env.sign(prepared, "sig_1", wakasek, env.code(wakasek_secret))
        assert first.status_code == 200, first.text
        assert first.headers["x-output-sha256"] == sha(first.content)
        assert first.headers["x-input-sha256"] == sha(prepared)

        # The same code cannot be used twice.
        replay = env.sign(first.content, "sig_2", wakasek, env.code(wakasek_secret))
        assert replay.status_code == 422 and _code(replay) == "totp_replayed"

        env.clock.tick()
        second = env.sign(first.content, "sig_2", kepsek, env.code(kepsek_secret))
        assert second.status_code == 200, second.text

        report = env.validate(second.content)
        assert report["status"] == "valid", report
        sigs = report["signatures"]
        assert isinstance(sigs, list) and len(sigs) == 2
        assert [s["key_id"] for s in sigs] == [wakasek, kepsek]
        assert sigs[0]["certification_level"] == "form_filling"
        assert all(s["intact"] and s["trusted"] and not s["revoked"] for s in sigs)

        # Revoking after the fact does not invalidate earlier signatures.
        revoke = env.client.post(
            f"/v1/signers/{kepsek}/revoke",
            headers=env.auth,
            json={"reason": "affiliation_changed", "actor_ref": str(uuid4())},
        )
        assert revoke.status_code == 200 and revoke.json()["status"] == "revoked"
        after = env.validate(second.content)
        assert after["status"] == "valid", after

        # A revoked signer can no longer sign.
        env.clock.tick()
        other = env.prepare(make_pdf(), signers=1)
        refused = env.sign(other, "sig_1", kepsek, env.code(kepsek_secret))
        assert refused.status_code == 409 and _code(refused) == "signer_revoked"

    def test_hash_mismatch(self, env: Env) -> None:
        pdf = make_pdf()
        resp = env.client.post(
            "/v1/documents/prepare",
            headers=env.auth,
            files={"file": ("d.pdf", pdf, "application/pdf")},
            data={
                "meta": json.dumps(
                    {
                        "input_sha256": "0" * 64,
                        "fields": [
                            {
                                "name": "sig_1",
                                "order": 1,
                                "page": 1,
                                "x": 10,
                                "y": 10,
                                "width": 100,
                                "height": 100,
                            }
                        ],
                    }
                )
            },
        )
        assert resp.status_code == 422 and _code(resp) == "input_hash_mismatch"

    def test_field_out_of_bounds_and_missing_page(self, env: Env) -> None:
        pdf = make_pdf()
        for field, code in (
            ({"x": 550, "y": 10}, "field_out_of_bounds"),
            ({"x": 10, "y": 10, "page": 3}, "page_not_found"),
        ):
            spec = {"name": "sig_1", "order": 1, "page": 1, "width": 100, "height": 100, **field}
            resp = env.client.post(
                "/v1/documents/prepare",
                headers=env.auth,
                files={"file": ("d.pdf", pdf, "application/pdf")},
                data={"meta": json.dumps({"input_sha256": sha(pdf), "fields": [spec]})},
            )
            assert resp.status_code == 422 and _code(resp) == code

    def test_tampering_is_detected(self, env: Env) -> None:
        key_id, secret = env.new_signer()
        prepared = env.prepare(make_pdf(), signers=1)
        signed = env.sign(prepared, "sig_1", key_id, env.code(secret))
        assert signed.status_code == 200
        content = bytearray(signed.content)
        marker = content.find(b"/MediaBox [0 0 595 842]")
        content[marker : marker + 23] = b"/MediaBox [0 0 595 843]"
        assert env.validate(bytes(content))["status"] == "invalid"

    def test_unsigned_pdf(self, env: Env) -> None:
        assert env.validate(make_pdf())["status"] == "unsigned"

    def test_not_a_pdf(self, env: Env) -> None:
        resp = env.client.post(
            "/v1/documents/validate",
            headers=env.auth,
            files={"file": ("x.pdf", b"hello", "application/pdf")},
        )
        assert resp.status_code == 422 and _code(resp) == "pdf_invalid"


def test_token_constant_is_long_enough() -> None:
    assert len(TOKEN) >= 32
