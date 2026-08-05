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

    <div class="flex min-h-full flex-col gap-6 p-4">
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

        <div class="grid gap-4 xl:grid-cols-[1.5fr_1fr]">
            <EarningsChart :weeklyData="weeklyData" />
            <EfficiencyChart :monthlyData="monthlyData" />
        </div>

        <div class="grid gap-4 xl:grid-cols-[2fr_1fr]">
            <ShiftsTable :shifts="recentShifts" />
            <FuelInvoiceUpload :initialInvoices="recentInvoices" />
        </div>
    </div>
</template>
