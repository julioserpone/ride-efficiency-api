<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import type { PropType } from 'vue';

const props = defineProps({
  weeklyData: {
    type: Array as PropType<Array<{ week_start: string; total_net_profit: number }>>,
    default: () => [],
  },
});

const series = computed(() => [
  {
    name: 'Ganancia neta',
    data: props.weeklyData.map((item) => Number(item.total_net_profit)),
  },
]);

const chartOptions = {
  chart: {
    toolbar: { show: false },
    zoom: { enabled: false },
    sparkline: { enabled: false },
  },
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 3 },
  xaxis: {
    categories: props.weeklyData.map((item) => item.week_start),
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: {
    labels: { formatter: (value: number) => `$${value.toFixed(0)}` },
  },
  tooltip: {
    y: { formatter: (value: number) => `$${value.toFixed(2)}` },
  },
  fill: { opacity: 0.8 },
  colors: ['#10B981'],
};
</script>

<template>
  <div class="rounded-[2rem] border border-border/70 bg-card/95 p-6 shadow-soft">
    <div class="mb-4 flex items-center justify-between gap-4">
      <div>
        <p class="text-sm font-medium uppercase tracking-[0.16em] text-muted-foreground">Ganancias por semana</p>
        <p class="text-xs text-muted-foreground">Últimas 8 semanas</p>
      </div>
    </div>

    <VueApexCharts type="bar" height="300" :options="chartOptions" :series="series" />
  </div>
</template>
