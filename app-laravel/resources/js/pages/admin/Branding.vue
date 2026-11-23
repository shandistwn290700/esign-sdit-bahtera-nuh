<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ImageUp, Trash2 } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import BrandingController from '@/actions/App/Http/Controllers/Admin/BrandingController';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import MdCard from '@/components/MdCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Identitas aplikasi', href: BrandingController.edit() },
        ],
    },
});

defineProps<{ settings: { name: string; logo_url: string | null } }>();

const preview = ref<string | null>(null);

function onPick(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }
    preview.value = file ? URL.createObjectURL(file) : null;
}

function clearPreview(): void {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }
    preview.value = null;
}

onBeforeUnmount(clearPreview);
</script>

<template>
    <Head title="Identitas aplikasi" />

    <div class="flex max-w-3xl flex-col gap-8 p-4 pt-6 md:px-6">
        <h1 class="sr-only">Identitas aplikasi</h1>

        <MdCard
            title="Nama aplikasi"
            description="Tampil di menu, halaman masuk, tab browser, dan halaman verifikasi publik."
        >
            <Form
                v-bind="BrandingController.update.form()"
                :options="{ preserveScroll: true }"
                class="flex flex-col gap-3 sm:flex-row sm:items-start"
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="name" class="sr-only">Nama aplikasi</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="settings.name"
                        required
                        minlength="3"
                        maxlength="60"
                    />
                    <InputError :message="errors.name" />
                </div>
                <Button type="submit" :disabled="processing">Simpan</Button>
            </Form>
        </MdCard>

        <MdCard title="Logo">
            <p class="mb-4 text-sm text-muted-foreground">
                PNG, JPG, WebP, atau SVG, maksimal 1 MB. Sebaiknya persegi
                dengan latar transparan.
            </p>

            <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                <div class="flex flex-col items-center gap-2">
                    <div
                        class="flex size-28 items-center justify-center rounded-xl border bg-muted/40 p-3"
                    >
                        <img
                            v-if="preview"
                            :src="preview"
                            alt="Pratinjau logo baru"
                            class="max-h-full max-w-full object-contain"
                        />
                        <AppLogoIcon v-else class="size-16 text-primary" />
                    </div>
                    <span class="text-xs text-muted-foreground">{{
                        preview
                            ? 'Pratinjau'
                            : settings.logo_url
                              ? 'Logo saat ini'
                              : 'Logo bawaan'
                    }}</span>
                </div>

                <div class="flex flex-1 flex-col gap-3">
                    <Form
                        v-bind="BrandingController.uploadLogo.form()"
                        :options="{ preserveScroll: true }"
                        reset-on-success
                        @success="clearPreview"
                        class="grid gap-3"
                        v-slot="{ errors, processing }"
                    >
                        <Label for="logo">Pilih berkas logo</Label>
                        <Input
                            id="logo"
                            name="logo"
                            type="file"
                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                            required
                            @change="onPick"
                        />
                        <InputError :message="errors.logo" />
                        <div>
                            <Button type="submit" :disabled="processing">
                                <ImageUp class="size-4" />
                                Unggah logo
                            </Button>
                        </div>
                    </Form>

                    <Form
                        v-if="settings.logo_url"
                        v-bind="BrandingController.removeLogo.form()"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="ghost"
                            class="text-destructive"
                            :disabled="processing"
                        >
                            <Trash2 class="size-4" />
                            Hapus logo
                        </Button>
                    </Form>
                </div>
            </div>
        </MdCard>
    </div>
</template>
