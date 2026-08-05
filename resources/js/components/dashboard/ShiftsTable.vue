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
  <div class="rounded-3xl border border-border bg-card p-6 shadow-sm">
    <div class="mb-4 flex items-center justify-between gap-4">
      <div>
        <p class="text-sm font-medium text-muted-foreground">Turnos recientes</p>
        <p class="text-xs text-muted-foreground">Últimos turnos registrados</p>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full border-separate border-spacing-y-3 text-left">
        <thead>
          <tr class="text-sm uppercase tracking-[0.12em] text-muted-foreground">
            <th class="px-4 py-3">Fecha</th>
            <th class="px-4 py-3">Km GPS</th>
            <th class="px-4 py-3">Tiempo</th>
            <th class="px-4 py-3">Viajes</th>
            <th class="px-4 py-3">Combustible</th>
            <th class="px-4 py-3">Ganancia neta</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="shift in shifts"
            :key="shift.id"
            class="rounded-3xl border border-border/70 bg-background/80"
          >
            <td class="px-4 py-4 font-medium text-foreground">{{ shift.shift_date }}</td>
            <td class="px-4 py-4">{{ shift.total_km_gps.toFixed(2) }}</td>
            <td class="px-4 py-4">{{ formatDuration(shift.total_minutes_connected) }}</td>
            <td class="px-4 py-4">{{ shift.total_trips_completed }}</td>
            <td class="px-4 py-4">{{ formatCurrency(shift.applied_fuel_cost) }}</td>
            <td class="px-4 py-4">
              <span :class="['rounded-full px-3 py-1 text-sm font-semibold', statusClass(shift.real_net_profit)]">
                {{ formatCurrency(shift.real_net_profit) }}
              </span>
            </td>
          </tr>
          <tr v-if="shifts.length === 0">
            <td colspan="6" class="px-4 py-8 text-center text-sm text-muted-foreground">
              No hay turnos disponibles.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
