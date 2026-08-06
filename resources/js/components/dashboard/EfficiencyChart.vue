<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import type { PropType } from 'vue';

const props = defineProps({
  monthlyData: {
    type: Array as PropType<Array<{ month_start: string; total_net_profit: number; total_km: number }>>,
    default: () => [],
  },
});

const series = computed(() => [
  {
    name: 'Eficiencia (km/ganancia)',
    data: props.monthlyData.map((item) => {
      const profit = Number(item.total_net_profit);
      const km = Number(item.total_km);
      return km > 0 ? Number((km / profit).toFixed(2)) : 0;
    }),
  },
]);

const chartOptions = {
  chart: {
    toolbar: { show: false },
    zoom: { enabled: false },
  },
  stroke: { curve: 'smooth', width: 3 },
  markers: { size: 4 },
  xaxis: {
    categories: props.monthlyData.map((item) => item.month_start),
  },
  tooltip: {
    y: { formatter: (value: number) => `${value.toFixed(2)} km/$` },
  },
  colors: ['#2563EB'],
};
</script>

<template>
  <div class="rounded-[2rem] border border-border/70 bg-card/95 p-6 shadow-soft">
    <div class="mb-4 flex items-center justify-between gap-4">
      <div>
        <p class="text-sm font-medium uppercase tracking-[0.16em] text-muted-foreground">Eficiencia</p>
        <p class="text-xs text-muted-foreground">Tendencia mensual</p>
      </div>
    </div>

    <VueApexCharts type="line" height="300" :options="chartOptions" :series="series" />
  </div>
</template>
