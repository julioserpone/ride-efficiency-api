import { ref } from 'vue';
import { useHttp } from '@inertiajs/vue3';

type WeeklyStat = {
  week_start: string;
  total_shifts: number;
  total_net_profit: number;
  total_km: number;
  total_fuel_cost: number;
  total_depreciation: number;
  total_trips: number;
  avg_daily_profit: number;
};

type MonthlyStat = {
  month_start: string;
  total_shifts: number;
  total_net_profit: number;
  total_km: number;
  total_fuel_cost: number;
  total_depreciation: number;
  total_trips: number;
  avg_daily_profit: number;
};

type SummaryData = {
  totals: {
    total_shifts: number;
    total_net_profit: number;
    total_km: number;
    total_fuel_cost: number;
    total_depreciation: number;
    total_trips: number;
    avg_daily_profit: number;
    avg_daily_km: number;
  };
  best_shift: Record<string, unknown> | null;
  worst_shift: Record<string, unknown> | null;
  current_month_profit: number;
};

type EfficiencyData = {
  efficiency: {
    profit_per_km: number;
    profit_per_hour: number;
    fuel_cost_per_km: number;
    avg_km_per_shift: number;
    avg_minutes_per_shift: number;
  };
};

export const useShiftStats = () => {
  const http = useHttp();

  const weekly = ref<WeeklyStat[]>([]);
  const monthly = ref<MonthlyStat[]>([]);
  const summary = ref<SummaryData | null>(null);
  const efficiency = ref<EfficiencyData | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  const fetchWeeklyStats = async (): Promise<void> => {
    loading.value = true;
    error.value = null;

    try {
      const response = (await http.get('/api/v1/stats/weekly')) as { weekly: WeeklyStat[] };
      weekly.value = response.weekly;
    } catch {
      error.value = 'No se pudieron cargar las estadísticas semanales.';
    } finally {
      loading.value = false;
    }
  };

  const fetchMonthlyStats = async (): Promise<void> => {
    loading.value = true;
    error.value = null;

    try {
      const response = (await http.get('/api/v1/stats/monthly')) as { monthly: MonthlyStat[] };
      monthly.value = response.monthly;
    } catch {
      error.value = 'No se pudieron cargar las estadísticas mensuales.';
    } finally {
      loading.value = false;
    }
  };

  const fetchSummary = async (): Promise<void> => {
    loading.value = true;
    error.value = null;

    try {
      const response = (await http.get('/api/v1/stats/summary')) as SummaryData;
      summary.value = response;
    } catch {
      error.value = 'No se pudo cargar el resumen de estadísticas.';
    } finally {
      loading.value = false;
    }
  };

  const fetchEfficiency = async (): Promise<void> => {
    loading.value = true;
    error.value = null;

    try {
      const response = (await http.get('/api/v1/stats/efficiency')) as EfficiencyData;
      efficiency.value = response;
    } catch {
      error.value = 'No se pudo cargar la eficiencia.';
    } finally {
      loading.value = false;
    }
  };

  return {
    weekly,
    monthly,
    summary,
    efficiency,
    loading,
    error,
    fetchWeeklyStats,
    fetchMonthlyStats,
    fetchSummary,
    fetchEfficiency,
  };
};
