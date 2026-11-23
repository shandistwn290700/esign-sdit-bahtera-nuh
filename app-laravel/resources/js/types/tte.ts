// Shapes of the Inertia props sent by the TTE controllers (see app/Http/Resources and controllers).

export type DocumentStatusValue =
    | 'in_progress'
    | 'completed'
    | 'rejected'
    | 'revoked';

export type SignerStepStatus =
    | 'waiting'
    | 'pending'
    | 'signed'
    | 'rejected'
    | 'skipped';

export type Box = { x: number; y: number; width: number; height: number };

export type DocumentSigner = {
    order: number;
    user: { id: number; name: string; position: string | null } | null;
    status: SignerStepStatus;
    page: number;
    box: Box;
    signed_at: string | null;
    rejection_reason: string | null;
};

export type DocumentItem = {
    public_id: string;
    title: string;
    number: string | null;
    subject: string | null;
    show_metadata_publicly: boolean;
    status: { value: DocumentStatusValue; label: string };
    current_step: number | null;
    verify_url: string;
    creator: { id: number; name: string } | null;
    signers?: DocumentSigner[];
    created_at: string | null;
    completed_at: string | null;
    revoked_at: string | null;
    revocation_reason: string | null;
};

export type SignerOption = {
    id: number;
    name: string;
    position: string | null;
};

export type PublicVerification = {
    public_id: string;
    status: {
        value: 'valid' | 'revoked' | 'rejected' | 'in_progress';
        label: string;
    };
    title: string | null;
    number: string | null;
    signatures: {
        name: string | null;
        position: string | null;
        signed_at: string | null;
    }[];
    signers_total: number;
    completed_at: string | null;
    revoked_at: string | null;
};

export type UploadVerificationResult = {
    signature_status: 'valid' | 'invalid' | 'unsigned';
    modified_after_last_signature: boolean;
    signatures: {
        signer_name: string | null;
        signed_at: string | null;
        valid: boolean;
        revoked: boolean;
        problems: string[];
    }[];
    registered: boolean;
    is_latest_version: boolean;
    verification: PublicVerification | null;
};

export type AdminUserRow = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'staff';
    position: string | null;
    nip: string | null;
    phone: string | null;
    is_active: boolean;
    pending_signatures: number;
    two_factor_enabled: boolean;
    signer: { enabled: boolean; activated: boolean; revoked: boolean };
};
