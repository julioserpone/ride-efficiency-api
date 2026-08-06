<script setup lang="ts">
import type { PropType } from 'vue';

const props = defineProps({
  shifts: {
    type: Array as PropType<Array<{
      id: number;
      shift_date: string;
      total_km_gps: number;
      total_minutes_connected: number;
      total_trips_completed: number;
      applied_fuel_cost: number;
      real_net_profit: number;
    }>>,
    default: () => [],
  },
});

const formatCurrency = (value: number): string => `$${value.toFixed(2)}`;
const formatDuration = (minutes: number): string => {
  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  return `${hours}h ${remainder}m`;
};

const statusClass = (profit: number): string => {
  if (profit > 0) return 'text-emerald-500';
  if (profit === 0) return 'text-amber-500';
  return 'text-rose-500';
};
</script>

<template>
  <div class="rounded-[2rem] border border-border/70 bg-card/95 p-6 shadow-soft">
    <div class="mb-4 flex items-center justify-between gap-4">
      <div>
        <p class="text-sm font-medium uppercase tracking-[0.16em] text-muted-foreground">Turnos recientes</p>
        <p class="text-xs text-muted-foreground">Últimos turnos registrados</p>
      </div>
    </div>

    <div class="space-y-3">
      <div
        v-for="shift in shifts"
        :key="shift.id"
        class="rounded-[1.75rem] border border-border/70 bg-background/90 p-4 shadow-sm transition hover:-translate-y-0.5"
      >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div class="min-w-0">
            <p class="text-sm font-semibold text-foreground">{{ shift.shift_date }}</p>
            <p class="mt-1 text-xs text-muted-foreground">
              {{ shift.total_trips_completed }} viajes · {{ formatDuration(shift.total_minutes_connected) }} · {{ shift.total_km_gps.toFixed(2) }} km
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-3 text-right">
            <span class="rounded-full bg-muted/40 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-muted-foreground">Km</span>
            <span :class="['rounded-full px-3 py-1 text-sm font-semibold', statusClass(shift.real_net_profit)]">
              {{ formatCurrency(shift.real_net_profit) }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="shifts.length === 0" class="rounded-[1.75rem] border border-border/70 bg-background/90 p-8 text-center text-sm text-muted-foreground">
        No hay turnos disponibles.
      </div>
    </div>
  </div>
</template>
