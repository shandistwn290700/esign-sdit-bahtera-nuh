<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import MdCard from '@/components/MdCard.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AdminUserRow } from '@/types/tte';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pengguna', href: UserController.index() }],
    },
});

defineProps<{ users: AdminUserRow[] }>();

type DialogKind = 'create' | 'edit' | 'deactivate' | 'reset' | 'revoke';
const open = ref<DialogKind | null>(null);
const target = ref<AdminUserRow | null>(null);

const page = usePage();
const isSelf = (u: AdminUserRow): boolean => u.id === page.props.auth.user.id;
// Name, position, email and NIP are in the certificate: changing them re-issues it.
const holdsCertificate = computed(
    () => !!target.value?.signer.enabled && !target.value.signer.revoked,
);

function show(kind: DialogKind, user: AdminUserRow | null = null): void {
    target.value = user;
    open.value = kind;
}

function signerState(u: AdminUserRow): {
    label: string;
    variant: 'default' | 'secondary' | 'destructive' | 'outline';
} {
    if (u.signer.revoked) {
        return { label: 'Dicabut', variant: 'destructive' };
    }
    if (u.signer.activated) {
        return { label: 'Aktif', variant: 'default' };
    }
    if (u.signer.enabled) {
        return { label: 'Menunggu aktivasi', variant: 'secondary' };
    }

    return { label: 'Tidak aktif', variant: 'outline' };
}

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <Head title="Pengguna" />

    <div class="flex flex-col gap-6 p-4 pt-6 md:px-6">
        <h1 class="sr-only">Pengguna</h1>
        <MdCard
            title="Pengguna"
            description="Buat akun dan atur siapa yang dapat menandatangani dokumen."
            flush
        >
            <template #actions>
                <Button
                    size="sm"
                    variant="outline"
                    class="text-foreground"
                    @click="show('create')"
                >
                    <UserPlus class="size-4" />
                    Tambah pengguna
                </Button>
            </template>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Nama
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Peran
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                2FA login
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Tanda tangan
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                <span class="sr-only">Aksi</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y border-t">
                        <tr
                            v-for="u in users"
                            :key="u.id"
                            :class="{ 'text-muted-foreground': !u.is_active }"
                        >
                            <td class="px-4 py-2">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{
                                        u.name
                                    }}</span>
                                    <Badge v-if="!u.is_active" variant="outline"
                                        >Nonaktif</Badge
                                    >
                                </p>
                                <p class="text-muted-foreground">
                                    {{ u.email }}
                                </p>
                                <p
                                    v-if="u.position"
                                    class="text-muted-foreground"
                                >
                                    {{ u.position }}
                                </p>
                            </td>
                            <td class="px-4 py-2">
                                {{ u.role === 'admin' ? 'Admin' : 'Staf' }}
                            </td>
                            <td class="px-4 py-2">
                                {{ u.two_factor_enabled ? 'Aktif' : 'Belum' }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge :variant="signerState(u).variant">{{
                                    signerState(u).label
                                }}</Badge>
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :aria-label="`Ubah data ${u.name}`"
                                        @click="show('edit', u)"
                                        >Ubah</Button
                                    >
                                    <template v-if="!u.is_active">
                                        <Form
                                            v-bind="
                                                UserController.activate.form(
                                                    u.id,
                                                )
                                            "
                                            :options="{ preserveScroll: true }"
                                            v-slot="{ processing }"
                                        >
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="secondary"
                                                :disabled="processing"
                                                >Aktifkan kembali</Button
                                            >
                                        </Form>
                                    </template>
                                    <Form
                                        v-else-if="
                                            !u.signer.enabled ||
                                            u.signer.revoked
                                        "
                                        v-bind="
                                            UserController.enableSigning.form(
                                                u.id,
                                            )
                                        "
                                        :options="{ preserveScroll: true }"
                                        v-slot="{ processing }"
                                    >
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="secondary"
                                            :disabled="processing"
                                        >
                                            Aktifkan tanda tangan
                                        </Button>
                                    </Form>
                                    <template v-else>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            @click="show('reset', u)"
                                            >Reset autentikator</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            class="text-destructive"
                                            @click="show('revoke', u)"
                                            >Cabut</Button
                                        >
                                    </template>
                                    <Button
                                        v-if="u.is_active && !isSelf(u)"
                                        size="sm"
                                        variant="ghost"
                                        class="text-destructive"
                                        :aria-label="`Nonaktifkan akun ${u.name}`"
                                        @click="show('deactivate', u)"
                                        >Nonaktifkan</Button
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <InputError
                class="px-6 pb-2"
                :message="($page.props.errors as Record<string, string>).action"
            />
        </MdCard>
    </div>

    <Dialog
        :open="open === 'create'"
        @update:open="(v) => (open = v ? 'create' : null)"
    >
        <DialogContent>
            <Form
                v-bind="UserController.store.form()"
                class="space-y-4"
                @success="open = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Tambah pengguna</DialogTitle>
                    <DialogDescription>
                        Tautan untuk membuat password dikirim ke email pengguna.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="name">Nama lengkap</Label>
                    <Input id="name" name="name" required maxlength="128" />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input id="email" name="email" type="email" required />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="position">Jabatan</Label>
                        <Input id="position" name="position" maxlength="128" />
                        <InputError :message="errors.position" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nip">NIP (18 digit)</Label>
                        <Input
                            id="nip"
                            name="nip"
                            inputmode="numeric"
                            maxlength="18"
                        />
                        <InputError :message="errors.nip" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="role">Peran</Label>
                    <select id="role" name="role" :class="fieldClass">
                        <option value="staff" selected>Staf</option>
                        <option value="admin">Admin</option>
                    </select>
                    <InputError :message="errors.role" />
                </div>
                <DialogFooter>
                    <DialogClose as-child
                        ><Button type="button" variant="outline"
                            >Batal</Button
                        ></DialogClose
                    >
                    <Button type="submit" :disabled="processing"
                        >Buat akun</Button
                    >
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog :open="open === 'edit'" @update:open="(v) => !v && (open = null)">
        <DialogContent v-if="target" :key="`edit-${target.id}`">
            <Form
                v-bind="UserController.update.form(target.id)"
                class="space-y-4"
                :options="{ preserveScroll: true }"
                @success="open = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Ubah data {{ target.name }}</DialogTitle>
                    <DialogDescription>
                        Setiap perubahan tercatat di audit log.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="edit-name">Nama lengkap</Label>
                    <Input
                        id="edit-name"
                        name="name"
                        :default-value="target.name"
                        required
                        maxlength="128"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="edit-email">Email</Label>
                    <Input
                        id="edit-email"
                        name="email"
                        type="email"
                        :default-value="target.email"
                        required
                    />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="edit-position">Jabatan</Label>
                        <Input
                            id="edit-position"
                            name="position"
                            :default-value="target.position ?? ''"
                            maxlength="128"
                        />
                        <InputError :message="errors.position" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-nip">NIP (18 digit)</Label>
                        <Input
                            id="edit-nip"
                            name="nip"
                            :default-value="target.nip ?? ''"
                            inputmode="numeric"
                            maxlength="18"
                        />
                        <InputError :message="errors.nip" />
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="edit-phone">Nomor WhatsApp</Label>
                        <Input
                            id="edit-phone"
                            name="phone"
                            type="tel"
                            :default-value="target.phone ?? ''"
                            maxlength="20"
                            placeholder="08…"
                        />
                        <InputError :message="errors.phone" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-role">Peran</Label>
                        <!-- Own role is fixed so the last admin cannot lock everyone out. -->
                        <input
                            v-if="isSelf(target)"
                            type="hidden"
                            name="role"
                            :value="target.role"
                        />
                        <select
                            id="edit-role"
                            :name="isSelf(target) ? undefined : 'role'"
                            :class="fieldClass"
                            :disabled="isSelf(target)"
                        >
                            <option
                                value="staff"
                                :selected="target.role === 'staff'"
                            >
                                Staf
                            </option>
                            <option
                                value="admin"
                                :selected="target.role === 'admin'"
                            >
                                Admin
                            </option>
                        </select>
                        <InputError :message="errors.role" />
                    </div>
                </div>
                <div
                    v-if="holdsCertificate"
                    class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950"
                >
                    <label class="flex items-start gap-2">
                        <input
                            type="checkbox"
                            name="reissue_certificate"
                            value="1"
                            class="mt-0.5 size-4"
                            aria-describedby="reissue-help"
                        />
                        <span class="font-medium"
                            >Terbitkan ulang sertifikat</span
                        >
                    </label>
                    <p id="reissue-help" class="mt-1 text-muted-foreground">
                        Wajib jika nama, jabatan, email, atau NIP berubah,
                        karena data itu tercantum di sertifikat. Sertifikat lama
                        dicabut (tanda tangan lama tetap sah) dan yang
                        bersangkutan harus mengaktifkan ulang autentikator tanda
                        tangannya.
                    </p>
                    <InputError :message="errors.reissue_certificate" />
                </div>
                <InputError :message="errors.action" />
                <DialogFooter>
                    <DialogClose as-child
                        ><Button type="button" variant="outline"
                            >Batal</Button
                        ></DialogClose
                    >
                    <Button type="submit" :disabled="processing">Simpan</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="open === 'deactivate'"
        @update:open="(v) => !v && (open = null)"
    >
        <DialogContent v-if="target" :key="`deactivate-${target.id}`">
            <Form
                v-bind="UserController.deactivate.form(target.id)"
                class="space-y-4"
                :options="{ preserveScroll: true }"
                @success="open = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Nonaktifkan {{ target.name }}?</DialogTitle>
                    <DialogDescription>
                        Ia langsung keluar dari semua perangkat dan tidak dapat
                        masuk lagi sampai diaktifkan kembali. Data dan riwayat
                        tanda tangannya tetap tersimpan.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="target.pending_signatures > 0"
                    role="alert"
                    class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950"
                >
                    {{ target.pending_signatures }} dokumen sedang menunggu
                    tanda tangannya. Dokumen itu akan tertahan; pembuatnya perlu
                    mencabut dan mengajukan ulang dengan penanda tangan lain.
                </p>
                <div class="grid gap-2">
                    <Label for="deactivate-reason">Alasan</Label>
                    <Input
                        id="deactivate-reason"
                        name="reason"
                        required
                        minlength="5"
                        maxlength="500"
                        placeholder="Contoh: Pindah tugas ke dinas lain"
                    />
                    <InputError :message="errors.reason ?? errors.action" />
                </div>
                <label
                    v-if="holdsCertificate"
                    class="flex items-start gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        name="revoke_certificate"
                        value="1"
                        checked
                        class="mt-0.5 size-4"
                    />
                    <span>
                        <span class="font-medium"
                            >Cabut juga sertifikat tanda tangannya</span
                        >
                        <span class="block text-muted-foreground"
                            >Disarankan bila ia pindah atau berhenti. Kosongkan
                            untuk nonaktif sementara (misalnya cuti).</span
                        >
                    </span>
                </label>
                <DialogFooter>
                    <DialogClose as-child
                        ><Button type="button" variant="outline"
                            >Batal</Button
                        ></DialogClose
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                        >Nonaktifkan</Button
                    >
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog :open="open === 'reset'" @update:open="(v) => !v && (open = null)">
        <DialogContent v-if="target">
            <Form
                v-bind="UserController.resetAuthenticator.form(target.id)"
                class="space-y-4"
                @success="open = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle
                        >Reset autentikator {{ target.name }}?</DialogTitle
                    >
                    <DialogDescription>
                        Kode dari HP lama tidak berlaku lagi. Yang bersangkutan
                        harus mengaktifkan ulang di Pengaturan &gt; Tanda
                        tangan.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="reset-reason">Alasan</Label>
                    <Input
                        id="reset-reason"
                        name="reason"
                        required
                        minlength="5"
                        maxlength="500"
                        placeholder="Contoh: HP hilang"
                    />
                    <InputError :message="errors.reason ?? errors.action" />
                </div>
                <DialogFooter>
                    <DialogClose as-child
                        ><Button type="button" variant="outline"
                            >Batal</Button
                        ></DialogClose
                    >
                    <Button type="submit" :disabled="processing">Reset</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog :open="open === 'revoke'" @update:open="(v) => !v && (open = null)">
        <DialogContent v-if="target">
            <Form
                v-bind="UserController.revokeSigning.form(target.id)"
                class="space-y-4"
                @success="open = null"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle
                        >Cabut sertifikat {{ target.name }}?</DialogTitle
                    >
                    <DialogDescription>
                        Ia tidak dapat menandatangani lagi. Tanda tangan yang
                        dibuat sebelum pencabutan tetap sah.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="revoke-reason">Alasan</Label>
                    <select
                        id="revoke-reason"
                        name="reason"
                        required
                        :class="fieldClass"
                    >
                        <option value="affiliation_changed">
                            Pindah tugas / tidak lagi menjabat
                        </option>
                        <option value="key_compromise">
                            HP atau akun diduga disalahgunakan
                        </option>
                        <option value="superseded">
                            Diganti sertifikat baru
                        </option>
                        <option value="cessation_of_operation">
                            Berhenti beroperasi
                        </option>
                        <option value="unspecified">Lainnya</option>
                    </select>
                    <InputError :message="errors.reason ?? errors.action" />
                </div>
                <div class="grid gap-2">
                    <Label for="revoke-note">Catatan (opsional)</Label>
                    <Input id="revoke-note" name="note" maxlength="500" />
                </div>
                <DialogFooter>
                    <DialogClose as-child
                        ><Button type="button" variant="outline"
                            >Batal</Button
                        ></DialogClose
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                        >Cabut sertifikat</Button
                    >
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
