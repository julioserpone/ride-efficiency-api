<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatsCard from '@/components/dashboard/StatsCard.vue';
import EarningsChart from '@/components/dashboard/EarningsChart.vue';
import EfficiencyChart from '@/components/dashboard/EfficiencyChart.vue';
import ShiftsTable from '@/components/dashboard/ShiftsTable.vue';
import FuelInvoiceUpload from '@/components/dashboard/FuelInvoiceUpload.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const page = usePage();

const summary = computed(() => page.props.value?.summary ?? {});
const weeklyData = computed(() => page.props.value?.weeklyData ?? []);
const monthlyData = computed(() => page.props.value?.monthlyData ?? []);
const recentShifts = computed(() => page.props.value?.recentShifts ?? []);
const recentInvoices = computed(() => page.props.value?.recentInvoices ?? []);
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto flex min-h-screen max-w-7xl flex-col gap-6 p-4 sm:p-6 lg:p-8">
        <div class="sticky top-16 z-20 rounded-[2rem] border border-border/70 bg-card/90 p-6 shadow-soft backdrop-blur-xl backdrop-saturate-150 transition-all duration-300">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl space-y-3">
                    <p class="text-xs uppercase tracking-[0.3em] text-muted-foreground">Panel de control</p>
                    <h1 class="text-3xl font-semibold text-foreground sm:text-4xl">Rendimiento de tu aplicación</h1>
                    <p class="text-sm leading-6 text-muted-foreground">Monitorea tus ganancias, kilómetros y recibos desde una experiencia limpia y moderna inspirada en iOS.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-[1.5rem] border border-border/70 bg-background/90 p-4 text-center shadow-sm">
                        <p class="text-xs uppercase tracking-[0.28em] text-muted-foreground">Turnos</p>
                        <p class="mt-2 text-2xl font-semibold text-foreground">{{ summary.total_shifts ?? 0 }}</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-border/70 bg-background/90 p-4 text-center shadow-sm">
                        <p class="text-xs uppercase tracking-[0.28em] text-muted-foreground">Ingresos</p>
                        <p class="mt-2 text-2xl font-semibold text-foreground">{{ summary.total_net_profit ? `$${summary.total_net_profit.toFixed(2)}` : '$0.00' }}</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-border/70 bg-background/90 p-4 text-center shadow-sm">
                        <p class="text-xs uppercase tracking-[0.28em] text-muted-foreground">Kilómetros</p>
                        <p class="mt-2 text-2xl font-semibold text-foreground">{{ summary.total_km?.toFixed(1) ?? '0.0' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <StatsCard
                title="Ganancia Neta Total"
                :value="`$${summary.total_net_profit?.toFixed(2) ?? '0.00'}`"
                subtitle="Total acumulado"
                icon="💰"
                variant="success"
            />
            <StatsCard
                title="Kilómetros Totales"
                :value="summary.total_km?.toFixed(1) ?? '0.0'"
                subtitle="Recorridos"
                icon="🚗"
                variant="primary"
            />
            <StatsCard
                title="Costo Combustible"
                :value="`$${summary.total_fuel_cost?.toFixed(2) ?? '0.00'}`"
                subtitle="Gasto total"
                icon="⛽"
                variant="warning"
            />
            <StatsCard
                title="Mejor Turno"
                :value="recentShifts[0]?.shift_date ?? 'N/A'"
                subtitle="Turno más reciente"
                icon="🏆"
                variant="primary"
            />
        </div>

        <div class="grid gap-4 xl:grid-cols-[1.75fr_1fr]">
            <EarningsChart :weeklyData="weeklyData" />
            <EfficiencyChart :monthlyData="monthlyData" />
        </div>

        <div class="grid gap-4 xl:grid-cols-[2fr_1fr]">
            <ShiftsTable :shifts="recentShifts" />
            <FuelInvoiceUpload :initialInvoices="recentInvoices" />
        </div>
    </div>
</template>
