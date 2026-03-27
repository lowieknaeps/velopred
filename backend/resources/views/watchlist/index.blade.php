<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Mijn Watchlist
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 rounded-lg">
                    {{ session('success') }}
                </div>
            @endif

            @if($riders->isEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-8 text-center">
                    <p class="text-gray-500 dark:text-gray-400 text-lg">Je watchlist is leeg.</p>
                    <a href="{{ url('/riders') }}" class="mt-4 inline-block text-blue-600 dark:text-blue-400 hover:underline">
                        Blader door alle renners →
                    </a>
                </div>
            @else
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Renner</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ploeg</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">PCS Ranking</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Specialiteit</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($riders as $rider)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4">
                                        <a href="{{ url('/riders/' . $rider->id) }}" class="font-medium text-gray-900 dark:text-white hover:text-blue-600">
                                            {{ $rider->first_name }} {{ $rider->last_name }}
                                        </a>
                                        <div class="text-sm text-gray-500">{{ $rider->nationality }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                        {{ $rider->team?->name ?? '—' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                        {{ $rider->pcs_ranking ?? '—' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                        @php
                                            $specialities = [
                                                'one_day' => $rider->pcs_speciality_one_day,
                                                'gc' => $rider->pcs_speciality_gc,
                                                'sprint' => $rider->pcs_speciality_sprint,
                                                'climber' => $rider->pcs_speciality_climber,
                                                'hills' => $rider->pcs_speciality_hills,
                                                'tt' => $rider->pcs_speciality_tt,
                                            ];
                                            arsort($specialities);
                                            $top = array_key_first($specialities);
                                        @endphp
                                        {{ ucfirst(str_replace('_', ' ', $top)) }}
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <form method="POST" action="{{ route('watchlist.destroy', $rider) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-500 hover:text-red-700 text-sm">
                                                Verwijderen
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
