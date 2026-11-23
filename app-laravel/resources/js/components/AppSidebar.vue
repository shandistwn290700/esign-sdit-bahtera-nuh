<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FileSignature, Files, Palette, ShieldCheck, Users } from '@lucide/vue';
import { computed } from 'vue';
import BrandingController from '@/actions/App/Http/Controllers/Admin/BrandingController';
import AdminDocumentController from '@/actions/App/Http/Controllers/Admin/DocumentController';
import DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { form as verifyForm } from '@/routes/verify';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems: NavItem[] = [
    {
        title: 'Dokumen saya',
        href: DocumentController.index(),
        icon: FileSignature,
    },
];

// Admin destinations get their own labelled group; staff never see the group at all.
const adminNavItems = computed<NavItem[]>(() =>
    page.props.auth.user?.is_admin
        ? [
              {
                  title: 'Semua dokumen',
                  href: AdminDocumentController.index(),
                  icon: Files,
              },
              { title: 'Pengguna', href: UserController.index(), icon: Users },
              {
                  title: 'Identitas aplikasi',
                  href: BrandingController.edit(),
                  icon: Palette,
              },
          ]
        : [],
);

const footerNavItems: NavItem[] = [
    {
        title: 'Cek keaslian dokumen',
        href: verifyForm.url(),
        icon: ShieldCheck,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="floating">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="DocumentController.index()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Dokumen" />
            <NavMain
                v-if="adminNavItems.length"
                :items="adminNavItems"
                label="Admin"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
