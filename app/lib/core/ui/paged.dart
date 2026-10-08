import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/models.dart';
import '../api/api_exception.dart';
import '../design/widgets.dart';
import '../l10n/translations.dart';
import 'errors.dart';

/// A list that arrives a page at a time.
@immutable
class PagedState<T> {
  const PagedState({
    this.items = const [],
    this.page = 0,
    this.lastPage = 1,
    this.total = 0,
    this.loading = true,
    this.loadingMore = false,
    this.error,
    this.loadMoreError,
  });

  final List<T> items;
  final int page;
  final int lastPage;
  final int total;

  /// The first page is on its way (and nothing is shown yet).
  final bool loading;
  final bool loadingMore;

  /// The first page failed.
  final ApiException? error;

  /// A later page failed: what is shown stays, and the footer offers a retry.
  final ApiException? loadMoreError;

  bool get hasMore => page < lastPage;

  bool get isEmpty => !loading && error == null && items.isEmpty;

  PagedState<T> copyWith({
    List<T>? items,
    int? page,
    int? lastPage,
    int? total,
    bool? loading,
    bool? loadingMore,
    ApiException? error,
    ApiException? loadMoreError,
    bool clearError = false,
    bool clearLoadMoreError = false,
  }) =>
      PagedState<T>(
        items: items ?? this.items,
        page: page ?? this.page,
        lastPage: lastPage ?? this.lastPage,
        total: total ?? this.total,
        loading: loading ?? this.loading,
        loadingMore: loadingMore ?? this.loadingMore,
        error: clearError ? null : error ?? this.error,
        loadMoreError: clearLoadMoreError ? null : loadMoreError ?? this.loadMoreError,
      );
}

/// Loads a list page by page. Subclasses say how to fetch one page; the first page loads by itself.
abstract class PagedNotifier<T> extends Notifier<PagedState<T>> {
  Future<Paged<T>> fetch(int page);

  @override
  PagedState<T> build() {
    Future<void>.microtask(refresh);

    return PagedState<T>();
  }

  /// Starts again from the first page, keeping what is shown until the new page arrives.
  Future<void> refresh() async {
    state = state.copyWith(loading: state.items.isEmpty, clearError: true, clearLoadMoreError: true);

    try {
      final page = await fetch(1);
      state = PagedState<T>(items: page.items, page: page.page, lastPage: page.lastPage, total: page.total, loading: false);
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e);
    }
  }

  Future<void> loadMore() async {
    if (state.loading || state.loadingMore || !state.hasMore) return;
    state = state.copyWith(loadingMore: true, clearLoadMoreError: true);

    try {
      final next = await fetch(state.page + 1);
      state = state.copyWith(
        items: [...state.items, ...next.items],
        page: next.page,
        lastPage: next.lastPage,
        total: next.total,
        loadingMore: false,
      );
    } on ApiException catch (e) {
      state = state.copyWith(loadingMore: false, loadMoreError: e);
    }
  }
}

/// Shows a [PagedNotifier]'s list: placeholders while it loads, an explanation and a retry when it fails, an
/// invitation when it is empty, and more rows as the end comes near.
class PagedListView<T> extends ConsumerStatefulWidget {
  const PagedListView({
    super.key,
    required this.provider,
    required this.itemBuilder,
    required this.empty,
    this.header,
    this.separated = true,
  });

  final NotifierProvider<PagedNotifier<T>, PagedState<T>> provider;
  final Widget Function(BuildContext context, T item) itemBuilder;

  /// What to show when there is nothing (already built: it knows whether a search is active).
  final Widget empty;
  final Widget? header;
  final bool separated;

  @override
  ConsumerState<PagedListView<T>> createState() => _PagedListViewState<T>();
}

class _PagedListViewState<T> extends ConsumerState<PagedListView<T>> {
  final ScrollController _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 400) {
        ref.read(widget.provider.notifier).loadMore();
      }
    });
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(widget.provider);
    final notifier = ref.read(widget.provider.notifier);

    if (state.loading) {
      return ListView(padding: const EdgeInsets.all(16), children: [if (widget.header != null) widget.header!, const QSkeletonList()]);
    }
    if (state.error != null && state.items.isEmpty) {
      return QErrorView(message: errorMessage(context, state.error!), retryLabel: context.t('Try again'), onRetry: notifier.refresh);
    }
    if (state.isEmpty) {
      return RefreshIndicator(
        onRefresh: notifier.refresh,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: [if (widget.header != null) widget.header!, SizedBox(height: 360, child: widget.empty)],
        ),
      );
    }

    final headerCount = widget.header == null ? 0 : 1;

    return RefreshIndicator(
      onRefresh: notifier.refresh,
      child: ListView.separated(
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
        itemCount: headerCount + state.items.length + 1,
        separatorBuilder: (context, index) => widget.separated && index >= headerCount ? const SizedBox(height: 10) : const SizedBox.shrink(),
        itemBuilder: (context, index) {
          if (index < headerCount) return widget.header!;
          final itemIndex = index - headerCount;
          if (itemIndex < state.items.length) return widget.itemBuilder(context, state.items[itemIndex]);

          if (state.loadMoreError != null) {
            return Center(child: QButton(label: context.t('Try again'), onPressed: notifier.loadMore, kind: QButtonKind.text, expand: false));
          }

          return state.loadingMore
              ? const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()))
              : const SizedBox(height: 8);
        },
      ),
    );
  }
}
