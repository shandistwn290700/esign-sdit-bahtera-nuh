<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';

const code = defineModel<string>({ required: true });

defineProps<{
    name?: string;
    id?: string;
    error?: string;
    disabled?: boolean;
    autofocus?: boolean;
}>();
</script>

<template>
    <div class="flex flex-col items-start gap-2">
        <!-- Submitted with the surrounding <Form>. -->
        <input type="hidden" :name="name ?? 'totp_code'" :value="code" />
        <InputOTP
            :id="id ?? 'totp_code'"
            v-model="code"
            :maxlength="6"
            :disabled="disabled"
            :autofocus="autofocus"
            inputmode="numeric"
            autocomplete="one-time-code"
        >
            <InputOTPGroup>
                <InputOTPSlot
                    v-for="index in 6"
                    :key="index"
                    :index="index - 1"
                />
            </InputOTPGroup>
        </InputOTP>
        <InputError :message="error" />
    </div>
</template>
